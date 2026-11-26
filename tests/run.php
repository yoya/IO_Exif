<?php
/*
 * regression tests (no dependency)
 * usage: php tests/run.php
 *   IO_Bit is loaded from vendor/ (composer install) or include_path.
 */

$root = dirname(dirname(__FILE__));
if (is_readable($root.'/vendor/autoload.php')) {
    require_once $root.'/vendor/autoload.php';
}
set_include_path($root.PATH_SEPARATOR.get_include_path());
require_once 'IO/Exif.php';

$failed = 0;
$passed = 0;

function check($name, $expect, $actual) {
    global $failed, $passed;
    if ($expect === $actual) {
        $passed++;
        return;
    }
    $failed++;
    echo "FAIL: $name".PHP_EOL;
    echo "  expect: ".var_export($expect, true).PHP_EOL;
    echo "  actual: ".var_export($actual, true).PHP_EOL;
}

// "Exif\0\0" + TIFF header + one IFD0
// $entries: [[tag, type, count, raw value bytes (in the byte order)], ...]
function exif($bigEndian, $entries) {
    $s = function ($v) use ($bigEndian) { return pack($bigEndian ? 'n' : 'v', $v); };
    $l = function ($v) use ($bigEndian) { return pack($bigEndian ? 'N' : 'V', $v); };
    $tiff = ($bigEndian ? "MM" : "II").$s(0x2a).$l(8);
    $extOffset = 8 + 2 + 12 * count($entries) + 4;
    $ext = '';
    $ifd = $s(count($entries));
    foreach ($entries as $entry) {
        list($tag, $type, $count, $value) = $entry;
        $ifd .= $s($tag).$s($type).$l($count);
        if (strlen($value) <= 4) {
            $ifd .= str_pad($value, 4, "\0");
        } else {
            $ifd .= $l($extOffset + strlen($ext));
            $ext .= $value;
        }
    }
    $ifd .= $l(0);
    return "Exif\0\0".$tiff.$ifd.$ext;
}

function parseExif($data) {
    $exif = new IO_Exif();
    $exif->parse($data);
    return $exif;
}

// E1: tag values must be decoded in the Exif byte order
$exif = parseExif(exif(true, [[0xA002, 4, 1, pack('N', 640)],
                              [0x011A, 5, 1, pack('NN', 72, 1)]]));
check('MM LONG', [640], $exif->IFDs['0th']->tagTable[0xA002]->data);
check('MM RATIONAL', [[72, 1]], $exif->IFDs['0th']->tagTable[0x011A]->data);
$exif = parseExif(exif(false, [[0xA002, 4, 1, pack('V', 640)]]));
check('II LONG', [640], $exif->IFDs['0th']->tagTable[0xA002]->data);

// E2: SHORT values are 16-bit
$exif = parseExif(exif(true, [[0x0112, 3, 1, pack('n', 0x0102)],
                              [0x0102, 3, 3, pack('nnn', 8, 8, 8)]]));
check('MM SHORT', [0x0102], $exif->IFDs['0th']->tagTable[0x0112]->data);
check('MM SHORT x3', [8, 8, 8], $exif->IFDs['0th']->tagTable[0x0102]->data);
$exif = parseExif(exif(false, [[0x0112, 3, 1, pack('v', 0x0102)]]));
check('II SHORT', [0x0102], $exif->IFDs['0th']->tagTable[0x0112]->data);

// E4: dump of a RATIONAL with denominator 0 must not throw
$exif = parseExif(exif(true, [[0x9204, 10, 1, pack('NN', 0, 0)],
                              [0x011A, 5, 1, pack('NN', 72, 1)]]));
ob_start();
try {
    $exif->dump();
    $err = null;
} catch (Throwable $e) {
    $err = get_class($e);
}
$out = ob_get_clean();
check('dump RATIONAL 0/0 no error', null, $err);
check('dump RATIONAL 0/0', 1, substr_count($out, " Data: [0]0/0\n"));
check('dump RATIONAL 72/1', 1, substr_count($out, " Data: [0]72/1=72\n"));

// E5: a sub-IFD pointer back to an ancestor IFD must not recurse forever
$err = null;
try {
    parseExif(exif(false, [[0x8769, 4, 1, pack('V', 8)]])); // Exif IFD -> IFD0
} catch (Exception $e) {
    $err = $e->getMessage();
}
check('IFD loop', 'IFD loop: Exif at offset 14', $err);

// E6: addIFDRemoveList() must update the property
$exif = new IO_Exif();
$exif->addIFDRemoveList('GPSInfo');
check('addIFDRemoveList', ['GPSInfo' => true], $exif->IFDRemoveList);

// E7: parse() must not raise a deprecation ("self" in callables)
$deprecated = [];
set_error_handler(function ($errno, $errstr) use (&$deprecated) {
    $deprecated []= $errstr;
    return true;
}, E_DEPRECATED);
parseExif(exif(true, [[0x0112, 3, 1, pack('n', 1)]]));
restore_error_handler();
check('parse without deprecation', [], $deprecated);

// TIFF 6.0 field types 6-12, in both byte orders (13 IFD is not an Exif type)
foreach ([true, false] as $bigEndian) {
    $bo = $bigEndian ? 'MM' : 'II';
    $s = $bigEndian ? 'n' : 'v';
    $l = $bigEndian ? 'N' : 'V';
    $f = $bigEndian ? 'G' : 'g';
    $d = $bigEndian ? 'E' : 'e';
    $exif = parseExif(exif($bigEndian, [[0xC000, 6, 2, "\x80\x7f"],
                                        [0xC001, 7, 5, "a\0b\xffc"],
                                        [0xC002, 8, 2, pack($s.$s, 0xfffe, 0x7fff)],
                                        [0xC003, 9, 1, pack($l, 0xfffffffd)],
                                        [0xC004, 10, 1, pack($l.$l, 0xffffffff, 3)],
                                        [0xC005, 11, 2, pack($f.$f, 0.5, -4)],
                                        [0xC006, 12, 1, pack($d, 1.0e100)]]));
    $tags = $exif->IFDs['0th']->tagTable;
    check("$bo SBYTE", [-128, 127], $tags[0xC000]->data);
    check("$bo UNDEFINED", "a\0b\xffc", $tags[0xC001]->data);
    check("$bo SSHORT", [-2, 32767], $tags[0xC002]->data);
    check("$bo SLONG", [-3], $tags[0xC003]->data);
    check("$bo SRATIONAL", [[-1, 3]], $tags[0xC004]->data);
    check("$bo FLOAT x2", [0.5, -4.0], $tags[0xC005]->data);
    check("$bo DOUBLE", [1.0e100], $tags[0xC006]->data);
    ob_start();
    $exif->dump();
    $out = ob_get_clean();
    check("$bo dump types", [1, 1, 1],
          [substr_count($out, "0xC000: Type:SBYTE Count:2 Data: [0]-128 [1]127\n"),
           substr_count($out, "0xC002: Type:SSHORT Count:2 Data: [0]-2 [1]32767\n"),
           substr_count($out, "0xC006: Type:DOUBLE Count:1 Offset:"),]);
}

// build(): Exif whose TIFF part is laid out like libtiff (values and image data before each IFD, gaps)
// $ifds: [name => [[tag, type, count, raw value | ['ifd', name] | ['data', bytes]], ...]]
// '0th' is first, '1th' is linked from 0th
function layoutExif($bigEndian, $ifds) {
    $s = function ($v) use ($bigEndian) { return pack($bigEndian ? 'n' : 'v', $v); };
    $l = function ($v) use ($bigEndian) { return pack($bigEndian ? 'N' : 'V', $v); };
    $out = ($bigEndian ? "MM" : "II").$s(0x2a)."\0\0\0\0";
    $patches = [[4, '0th']];
    $ifdOffsets = [];
    foreach ($ifds as $name => $entries) {
        $valueOffsets = [];
        foreach ($entries as $k => $entry) {
            $value = $entry[3];
            $bytes = is_array($value) ? (($value[0] === 'data') ? $value[1] : '') : $value;
            if (strlen($bytes) > 4 || (is_array($value) && $value[0] === 'data')) {
                $out .= "GG";
                $valueOffsets[$k] = strlen($out);
                $out .= $bytes.((strlen($bytes) % 2) ? "G" : "");
            }
        }
        $ifdOffsets[$name] = strlen($out);
        $out .= $s(count($entries));
        foreach ($entries as $k => $entry) {
            list($tag, $type, $count, $value) = $entry;
            $out .= $s($tag).$s($type).$l($count);
            if (is_array($value) && ($value[0] === 'ifd')) {
                $patches []= [strlen($out), $value[1]];
                $out .= "\0\0\0\0";
            } else if (isset($valueOffsets[$k])) {
                $out .= $l($valueOffsets[$k]);
            } else {
                $out .= str_pad($value, 4, "\0");
            }
        }
        if (($name === '0th') && isset($ifds['1th'])) {
            $patches []= [strlen($out), '1th'];
        }
        $out .= "\0\0\0\0GGGG";
    }
    foreach ($patches as $patch) {
        $out = substr_replace($out, $l($ifdOffsets[$patch[1]]), $patch[0], 4);
    }
    return "Exif\0\0".$out;
}

// tag values, without the offsets that build() renumbers
function tagValues($exif) {
    $values = [];
    foreach ($exif->IFDs as $name => $ifd) {
        foreach ($ifd->tagTable as $tagNo => $tag) {
            if (in_array($tagNo, [0x0111, 0x0201, 0x8769, 0x8825, 0xA005], true)) {
                $values[$name][$tagNo] = 'offset';
            } else {
                $values[$name][$tagNo] = [$tag->type, $tag->count, $tag->data];
            }
        }
    }
    return $values;
}

// image data referred to by offset / byte count tags
function imageData($exif) {
    $list = [];
    foreach ($exif->IFDs as $name => $ifd) {
        foreach ([0x0111 => 0x0117, 0x0201 => 0x0202] as $offsetTag => $countTag) {
            if (isset($ifd->tagTable[$offsetTag])) {
                foreach ($ifd->tagTable[$offsetTag]->data as $i => $offset) {
                    $list[$name][$offsetTag][] = md5(substr($exif->exifData, 6 + $offset, $ifd->tagTable[$countTag]->data[$i]));
                }
            }
        }
    }
    return $list;
}

function dumpWithoutOffsets($exif) {
    ob_start();
    $exif->dump(['name' => true]);
    $out = ob_get_clean();
    $out = preg_replace('/(BaseOffset|ExtendOffset|ExtendSize|Offset):\d*/', '$1:*', $out);
    return preg_replace('/^(\s+0x(0111|0201)\(.*Data:).*$/m', '$1 *', $out);
}

function buildError($exif) {
    try {
        $exif->build();
    } catch (Exception $e) {
        return $e->getMessage();
    }
    return null;
}

foreach ([true, false] as $bigEndian) {
    $bo = $bigEndian ? 'MM' : 'II';
    $s = $bigEndian ? 'n' : 'v';
    $l = $bigEndian ? 'N' : 'V';
    $f = $bigEndian ? 'G' : 'g';
    $thumb = "\xff\xd8thumbnail\xff\xd9";
    $data = layoutExif($bigEndian, [
        '0th' => [[0x010F, 2, 6, "Maker\0"],
                  [0x0112, 3, 1, pack($s, 1)],
                  [0x011A, 5, 1, pack($l.$l, 72, 1)],
                  [0x8769, 4, 1, ['ifd', 'Exif']],
                  [0x8825, 4, 1, ['ifd', 'GPSInfo']],
                  [0xC000, 6, 3, "\x80\x01\xff"],
                  [0xC001, 8, 3, pack($s.$s.$s, 0x8000, 1, 0xffff)],
                  [0xC002, 11, 2, pack($f.$f, 1.5, -0.25)],
                  [0xC003, 12, 1, pack($bigEndian ? 'E' : 'e', 3.0e-5)]],
        'Exif' => [[0x9000, 7, 4, "0230"],
                   [0x9204, 10, 1, pack($l.$l, 0xffffffff, 3)],
                   [0x927C, 7, 9, "MakerNote"],
                   [0xA005, 4, 1, ['ifd', 'Interoperability']]],
        'Interoperability' => [[0x0001, 2, 4, "R98\0"]],
        'GPSInfo' => [[0x0002, 5, 3, pack($l.$l.$l.$l.$l.$l, 35, 1, 40, 1, 0, 1)]],
        '1th' => [[0x0103, 3, 1, pack($s, 6)],
                  [0x0201, 4, 1, ['data', $thumb]],
                  [0x0202, 4, 1, pack($l, strlen($thumb))]],
        ]);
    $exif = parseExif($data);
    check("$bo build input IFDs", ['0th', 'Exif', 'Interoperability', 'GPSInfo', '1th'], array_keys($exif->IFDs));
    $out = $exif->build();
    $exif2 = parseExif($out);
    check("$bo build header", "Exif\0\0".($bigEndian ? "MM" : "II").pack($s, 0x2a).pack($l, 8), substr($out, 0, 14));
    check("$bo build IFDs", array_keys($exif->IFDs), array_keys($exif2->IFDs));
    check("$bo build tag values", tagValues($exif), tagValues($exif2));
    check("$bo build thumbnail", imageData($exif), imageData($exif2));
    check("$bo build thumbnail bytes", $thumb, substr($out, 6 + $exif2->IFDs['1th']->tagTable[0x0201]->data[0], strlen($thumb)));
    check("$bo build dump", dumpWithoutOffsets($exif), dumpWithoutOffsets($exif2));
    check("$bo build again identical", bin2hex($out), bin2hex($exif2->build()));
    check("$bo build smaller (no gaps)", true, strlen($out) < strlen($data));
    $aligned = true;
    foreach ($exif2->IFDs as $ifd) {
        $aligned = $aligned && (($ifd->baseOffset - 6) % 2 === 0);
        foreach ($ifd->tagTable as $tag) {
            $aligned = $aligned && ($tag->offset === null || $tag->offset % 2 === 0);
        }
    }
    check("$bo build word aligned", true, $aligned);
    // changed values are written
    $exif->IFDs['0th']->tagTable[0x010F]->data = "Another Maker\0";
    $exif->IFDs['GPSInfo']->tagTable[0x0002]->data[1] = [41, 1];
    $exif3 = parseExif($exif->build());
    check("$bo build changed ASCII", [14, "Another Maker\0"],
          [$exif3->IFDs['0th']->tagTable[0x010F]->count, $exif3->IFDs['0th']->tagTable[0x010F]->data]);
    check("$bo build changed RATIONAL", [[35, 1], [41, 1], [0, 1]], $exif3->IFDs['GPSInfo']->tagTable[0x0002]->data);
    check("$bo build changed, rest kept", tagValues($exif2)['Exif'], tagValues($exif3)['Exif']);
}

// build(): tracked sample files
foreach (array_merge(glob(dirname(__FILE__).'/../test/*.exif'), glob(dirname(__FILE__).'/../test/*.EXIF')) as $file) {
    $exif = parseExif(file_get_contents($file));
    $out = $exif->build();
    $exif2 = parseExif($out);
    $name = basename($file);
    check("$name build tag values", tagValues($exif), tagValues($exif2));
    check("$name build thumbnail", imageData($exif), imageData($exif2));
    check("$name build dump", dumpWithoutOffsets($exif), dumpWithoutOffsets($exif2));
    check("$name build again identical", md5($out), md5($exif2->build()));
}

// build(): data that can not be carried over is an error
check("build FreeOffsets", "build: 0x0120(FreeOffsets) in IFD:0th is not supported",
      buildError(parseExif(exif(false, [[0x0120, 4, 1, pack('V', 8)], [0x0121, 4, 1, pack('V', 4)]]))));
check("build SubIFDs", "build: 0x014A in IFD:0th points to an IFD at offset 100 that was not parsed",
      buildError(parseExif(exif(false, [[0x014A, 4, 1, pack('V', 100)]]))));
check("build thumbnail without length", "build: 0x0201 in IFD:0th needs 0x0202 with the same count",
      buildError(parseExif(exif(false, [[0x0201, 4, 1, pack('V', 8)]]))));
check("build thumbnail out of range", "build: 0x0201 in IFD:0th refers beyond the end of data (offset:8 length:1000)",
      buildError(parseExif(exif(false, [[0x0201, 4, 1, pack('V', 8)], [0x0202, 4, 1, pack('V', 1000)]]))));
$data = layoutExif(false, ['0th' => [[0x0100, 4, 1, pack('V', 1)]], '1th' => [[0x0100, 4, 1, pack('V', 2)]]]);
$data .= pack('v', 0).pack('V', 0);
$data = substr_replace($data, pack('V', strlen($data) - 6 - 6), strpos($data, pack('vvVV', 0x0100, 4, 1, 2)) + 12, 4);
check("build IFD chain after 1th", "build: IFD:1th is followed by another IFD (not supported)", buildError(parseExif($data)));

// the library loads its own files without include_path (Composer install)
$bitFile = stream_resolve_include_path('IO/Bit.php');
$code = 'set_include_path("/nonexistent"); require_once '.var_export($bitFile, true).';'
      . ' require_once '.var_export($root.'/IO/Exif.php', true).'; echo class_exists("IO_Exif") ? "ok" : "ng";';
$out = shell_exec(escapeshellarg(PHP_BINARY).' -d display_errors=stderr -r '.escapeshellarg($code).' 2>&1');
check('load without include_path', 'ok', $out);

echo "passed: $passed, failed: $failed".PHP_EOL;
exit($failed ? 1 : 0);
