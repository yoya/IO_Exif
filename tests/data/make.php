<?php
/*
 * generates the synthetic test data in this directory (Exif 2.3: "Exif\0\0" + TIFF 6.0)
 * usage: php tests/data/make.php
 *   every run writes the same bytes. No library code is used.
 *   tests/run.php includes this file and compares makeFiles() with the files.
 *
 * layout of the TIFF files: header, then each IFD followed by its
 * out-of-line values (tag order, word aligned), then the image data
 * (strips, JPEG thumbnail) in the order given by 'data'.
 */

$dir = dirname(__FILE__);

// value bytes of a field
function fieldBytes($bigEndian, $type, $values) {
    if (is_string($values)) { // ASCII, UNDEFINED
        return $values;
    }
    $fmt = [1 => 'C', 3 => $bigEndian ? 'n' : 'v', 4 => $bigEndian ? 'N' : 'V',
            6 => 'c', 8 => $bigEndian ? 'n' : 'v', 9 => $bigEndian ? 'N' : 'V',
            11 => $bigEndian ? 'G' : 'g', 12 => $bigEndian ? 'E' : 'e', 13 => $bigEndian ? 'N' : 'V'];
    $l = $bigEndian ? 'N' : 'V';
    $out = '';
    foreach ($values as $v) {
        if ($type === 5 || $type === 10) { // RATIONAL, SRATIONAL: [numerator, denominator]
            $out .= pack($l, $v[0] & 0xffffffff).pack($l, $v[1] & 0xffffffff);
        } else if ($type === 8) {
            $out .= pack($fmt[8], $v & 0xffff);
        } else if ($type === 9) {
            $out .= pack($fmt[9], $v & 0xffffffff);
        } else {
            $out .= pack($fmt[$type], $v);
        }
    }
    return $out;
}

/*
 * $ifds: [name => ['next' => name or null, 'tags' => [[tag, type, values], ...]]]
 *   values: array / string, or ['ifd', name] (one IFD offset, LONG),
 *   ['ifds', [name, ...]] (IFD offsets), ['data', [bytes, ...]] (offsets to image data)
 * $dataOrder: IFD names, the order of the image data at the end
 */
function makeTIFF($bigEndian, $version, $ifds, $dataOrder) {
    $s = $bigEndian ? 'n' : 'v';
    $l = $bigEndian ? 'N' : 'V';
    $count = function ($tag) {
        list($tagNo, $type, $values) = $tag;
        if (is_string($values)) {
            return strlen($values);
        }
        if (isset($values[0]) && is_string($values[0])) {
            return ($values[0] === 'ifd') ? 1 : count($values[1]);
        }
        return count($values);
    };
    $size = function ($tag) use ($count) {
        $unit = [1 => 1, 2 => 1, 3 => 2, 4 => 4, 5 => 8, 6 => 1, 7 => 1,
                 8 => 2, 9 => 4, 10 => 8, 11 => 4, 12 => 8, 13 => 4];
        return $unit[$tag[1]] * $count($tag);
    };
    // layout
    $offset = 8;
    $ifdOffsets = [];
    $valueOffsets = [];
    foreach ($ifds as $name => $ifd) {
        $offset += $offset % 2;
        $ifdOffsets[$name] = $offset;
        $offset += 2 + 12 * count($ifd['tags']) + 4;
        foreach ($ifd['tags'] as $k => $tag) {
            if ($size($tag) > 4) {
                $offset += $offset % 2;
                $valueOffsets[$name][$k] = $offset;
                $offset += $size($tag);
            }
        }
    }
    $dataOffsets = [];
    foreach ($dataOrder as $name) {
        foreach ($ifds[$name]['tags'] as $k => $tag) {
            if (is_array($tag[2]) && isset($tag[2][0]) && $tag[2][0] === 'data') {
                foreach ($tag[2][1] as $bytes) {
                    $offset += $offset % 2;
                    $dataOffsets[$name][$k][] = $offset;
                    $offset += strlen($bytes);
                }
            }
        }
    }
    // write
    $out = ($bigEndian ? "MM" : "II").pack($s, $version).pack($l, $ifdOffsets[array_keys($ifds)[0]]);
    $pad = function () use (&$out) {
        if (strlen($out) % 2) {
            $out .= "\0";
        }
    };
    foreach ($ifds as $name => $ifd) {
        $pad();
        $out .= pack($s, count($ifd['tags']));
        $values = [];
        foreach ($ifd['tags'] as $k => $tag) {
            list($tagNo, $type, $v) = $tag;
            if (is_array($v) && isset($v[0]) && $v[0] === 'ifd') {
                $v = [$ifdOffsets[$v[1]]];
            } else if (is_array($v) && isset($v[0]) && $v[0] === 'ifds') {
                $v = array_map(function ($n) use ($ifdOffsets) { return $ifdOffsets[$n]; }, $v[1]);
            } else if (is_array($v) && isset($v[0]) && $v[0] === 'data') {
                $v = $dataOffsets[$name][$k];
            }
            $bytes = fieldBytes($bigEndian, $type, $v);
            $out .= pack($s, $tagNo).pack($s, $type).pack($l, $count($tag));
            if (isset($valueOffsets[$name][$k])) {
                $out .= pack($l, $valueOffsets[$name][$k]);
                $values[] = $bytes;
            } else {
                $out .= str_pad($bytes, 4, "\0");
            }
        }
        $out .= pack($l, ($ifd['next'] === null) ? 0 : $ifdOffsets[$ifd['next']]);
        foreach ($values as $bytes) {
            $pad();
            $out .= $bytes;
        }
    }
    foreach ($dataOrder as $name) {
        foreach ($ifds[$name]['tags'] as $k => $tag) {
            if (is_array($tag[2]) && isset($tag[2][0]) && $tag[2][0] === 'data') {
                foreach ($tag[2][1] as $bytes) {
                    $pad();
                    $out .= $bytes;
                }
            }
        }
    }
    if (strlen($out) !== $offset) {
        throw new Exception("layout error");
    }
    return $out;
}

// strips of an RGB 8bit image, width x height, rowsPerStrip
$thumb = "\xff\xd8\xff\xdb\0\x03\0\xff\xd9"; // JPEG thumbnail, 9 bytes (odd)

/*
 * Exif (APP1 payload, "Exif\0\0" + TIFF): 0th with every field type 1-12,
 * Exif (with Interoperability) and GPS IFDs, 1th with a JPEG thumbnail
 */
function exifTIFF($bigEndian) {
    global $thumb;
    $ifds = [
        '0th' => ['next' => '1th', 'tags' => [
            [0x010E, 2, "synthetic exif\0"],               // ImageDescription (15, odd)
            [0x010F, 2, "IO_Exif\0"],                      // Make
            [0x0110, 2, "t\0"],                            // Model (inline)
            [0x0112, 3, [1]],                              // Orientation
            [0x011A, 5, [[72, 1]]],                        // XResolution
            [0x011B, 5, [[144, 2]]],                       // YResolution
            [0x0128, 3, [2]],                              // ResolutionUnit
            [0x0131, 2, "make.php\0"],                     // Software
            [0x0132, 2, "2026:11:28 01:02:03\0"],          // DateTime
            [0x0213, 3, [1]],                              // YCbCrPositioning
            [0x8769, 4, ['ifd', 'Exif']],
            [0x8825, 4, ['ifd', 'GPSInfo']],
            [0xC000, 1, [0, 255]],                         // BYTE
            [0xC001, 6, [-128, 127, -1, 0, 1]],            // SBYTE x5 (out-of-line, odd)
            [0xC002, 8, [-32768, 32767]],                  // SSHORT
            [0xC003, 9, [-1]],                             // SLONG
            [0xC004, 10, [[-5, 2]]],                       // SRATIONAL
            [0xC005, 11, [-0.5]],                          // FLOAT
            [0xC006, 11, [2.0, 0.125, -8.0]],              // FLOAT x3
            [0xC007, 12, [1.0e-3, -1.0e10]],               // DOUBLE x2
            [0xC008, 4, [0, 0xFFFFFFFF]],                  // LONG x2
            [0xC009, 3, [1, 2, 3]],                        // SHORT x3 (out-of-line)
            [0xC00A, 7, ""],                               // UNDEFINED, count 0
            ]],
        'Exif' => ['next' => null, 'tags' => [
            [0x829A, 5, [[1, 250]]],                       // ExposureTime
            [0x829D, 5, [[28, 10]]],                       // FNumber
            [0x8827, 3, [400]],                            // PhotographicSensitivity
            [0x9000, 7, "0232"],                           // ExifVersion
            [0x9003, 2, "2026:11:28 01:02:03\0"],          // DateTimeOriginal
            [0x9101, 7, "\1\2\3\0"],                       // ComponentsConfiguration
            [0x9204, 10, [[-1, 3]]],                       // ExposureBiasValue
            [0x927C, 7, "MAKER\0\1"],                      // MakerNote (7, odd)
            [0x9286, 7, "ASCII\0\0\0comment"],             // UserComment
            [0xA000, 7, "0100"],                           // FlashpixVersion
            [0xA001, 3, [1]],                              // ColorSpace: sRGB
            [0xA002, 4, [640]],                            // PixelXDimension
            [0xA003, 3, [480]],                            // PixelYDimension (SHORT)
            [0xA005, 4, ['ifd', 'Interoperability']]]],
        'Interoperability' => ['next' => null, 'tags' => [
            [0x0001, 2, "R98\0"],
            [0x0002, 7, "0100"]]],
        'GPSInfo' => ['next' => null, 'tags' => [
            [0x0000, 1, [2, 3, 0, 0]],                     // GPSVersionID
            [0x0001, 2, "N\0"],                            // GPSLatitudeRef
            [0x0002, 5, [[35, 1], [40, 1], [1234, 100]]],  // GPSLatitude
            [0x0003, 2, "E\0"],                            // GPSLongitudeRef
            [0x0004, 5, [[139, 1], [45, 1], [0, 1]]],      // GPSLongitude
            [0x0007, 5, [[12, 1], [34, 1], [56, 1]]],      // GPSTimeStamp
            [0x001D, 2, "2026:11:28\0"]]],                 // GPSDateStamp
        '1th' => ['next' => null, 'tags' => [
            [0x0103, 3, [6]],                              // Compression: JPEG
            [0x011A, 5, [[72, 1]]],
            [0x011B, 5, [[72, 1]]],
            [0x0128, 3, [2]],
            [0x0201, 4, ['data', [$thumb]]],               // JPEGInterchangeFormat
            [0x0202, 4, [strlen($thumb)]]]],               // JPEGInterchangeFormatLength
        ];
    return makeTIFF($bigEndian, 0x2A, $ifds, ['0th', '1th']);
}

// 1th with an uncompressed RGB thumbnail in 2 strips (SHORT offsets)
function stripsTIFF($bigEndian) {
    $strips = ["\x10\x20\x30\x40\x50\x60", "\x70\x80\x90"]; // 2x1 and 1x1 pixels
    return makeTIFF($bigEndian, 0x2A, [
        '0th' => ['next' => '1th', 'tags' => [[0x0112, 3, [3]]]],
        '1th' => ['next' => null, 'tags' => [
            [0x0100, 3, [2]],                              // ImageWidth
            [0x0101, 3, [2]],                              // ImageLength
            [0x0102, 3, [8, 8, 8]],                        // BitsPerSample
            [0x0103, 3, [1]],                              // Compression: none
            [0x0106, 3, [2]],                              // PhotometricInterpretation: RGB
            [0x0111, 3, ['data', $strips]],                // StripOffsets (SHORT)
            [0x0115, 3, [3]],                              // SamplesPerPixel
            [0x0116, 3, [1]],                              // RowsPerStrip
            [0x0117, 3, [6, 3]]]]], ["0th", "1th"]);       // StripByteCounts
}

function makeFiles() {
    $files = [];
    $files['all-mm.exif'] = "Exif\0\0".exifTIFF(true);
    $files['all-ii.exif'] = "Exif\0\0".exifTIFF(false);
    $files['strips-mm.exif'] = "Exif\0\0".stripsTIFF(true);
    // only 0th, no next IFD
    $files['0th-only-ii.exif'] = "Exif\0\0".makeTIFF(false, 0x2A, [
        '0th' => ['next' => null, 'tags' => [[0x0112, 3, [8]]]]], []);
    // truncated in the middle of the 0th entries
    $files['truncated.exif'] = substr($files['all-ii.exif'], 0, 6 + 8 + 2 + 12 * 4 + 3);
    return $files;
}

if (realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    foreach (makeFiles() as $name => $data) {
        file_put_contents("$dir/$name", $data);
    }
}
