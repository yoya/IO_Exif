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

// build(): IFDRemoveList leaves out the IFDs, the IFDs only they point to, and the pointers
$exif = parseExif(file_get_contents(dirname(__FILE__).'/../test/CA3G0035.EXIF'));
$exif->addIFDRemoveList('Exif');
$exif->addIFDRemoveList('NoSuchIFD');
$exif2 = parseExif($exif->build());
check("remove Exif", [['0th', '1th'], false],
      [array_keys($exif2->IFDs), isset($exif2->IFDs['0th']->tagTable[0x8769])]);
check("remove Exif, rest kept", [tagValues($exif)['0th'][0x010F], tagValues($exif)['1th'], imageData($exif)],
      [tagValues($exif2)['0th'][0x010F], tagValues($exif2)['1th'], imageData($exif2)]);
$exif->addIFDRemoveList('1th');
$exif2 = parseExif($exif->build());
check("remove 1th", ['0th'], array_keys($exif2->IFDs));
$exif->addIFDRemoveList('0th');
check("remove 0th", "build: IFD:0th can not be removed", buildError($exif));

// the library loads its own files without include_path (Composer install)
$bitFile = stream_resolve_include_path('IO/Bit.php');
$code = 'set_include_path("/nonexistent"); require_once '.var_export($bitFile, true).';'
      . ' require_once '.var_export($root.'/IO/Exif.php', true).'; echo class_exists("IO_Exif") ? "ok" : "ng";';
$out = shell_exec(escapeshellarg(PHP_BINARY).' -d display_errors=stderr -r '.escapeshellarg($code).' 2>&1');
check('load without include_path', 'ok', $out);

/*
 * synthetic test data (tests/data, made by tests/data/make.php)
 */
function checkThrows($name, $pattern, $func) {
    try {
        $func();
        check($name, "exception matching $pattern", 'no exception');
    } catch (Exception $e) {
        check($name, 1, preg_match($pattern, $e->getMessage()));
    }
}

// runs php with the same include_path; [exit status, stdout, stderr] (exit -1 on timeout)
function runPHP($args, $timeout = 60) {
    $cmd = array_merge([PHP_BINARY, '-d', 'include_path='.get_include_path(), '-d', 'error_reporting=E_ALL',
                        '-d', 'display_errors=stderr', '-d', 'log_errors=0'], $args);
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(dirname(__FILE__)));
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $out = $err = '';
    $start = microtime(true);
    while (true) {
        $out .= stream_get_contents($pipes[1]);
        $err .= stream_get_contents($pipes[2]);
        $status = proc_get_status($proc);
        if (! $status['running']) {
            $out .= stream_get_contents($pipes[1]);
            $err .= stream_get_contents($pipes[2]);
            proc_close($proc);
            return [$status['exitcode'], $out, $err];
        }
        if (microtime(true) - $start > $timeout) {
            proc_terminate($proc, 9);
            proc_close($proc);
            return [-1, $out, $err."timeout"];
        }
        usleep(10000);
    }
}

$dataDir = dirname(__FILE__).'/data';
require_once $dataDir.'/make.php';

// the files are up to date (make.php writes the same bytes)
$madeFiles = makeFiles();
foreach ($madeFiles as $name => $bytes) {
    check("data $name is made by make.php", md5($bytes), md5_file("$dataDir/$name"));
}
$names = array_keys($madeFiles);
sort($names);
check("data files", $names, array_values(array_diff(array_map('basename', glob("$dataDir/*")), ['make.php'])));
$madeFiles = array_keys($madeFiles);

function dumpExif($exif, $opts) {
    ob_start();
    try {
        $exif->dump($opts);
        $err = null;
    } catch (Throwable $e) {
        $err = get_class($e).': '.$e->getMessage();
    }
    return [ob_get_clean(), $err];
}

// parse: every field type and IFD of all-mm.exif / all-ii.exif
foreach (['MM' => 'all-mm.exif', 'II' => 'all-ii.exif'] as $bo => $file) {
    $data = file_get_contents("$dataDir/$file");
    $exif = parseExif($data);
    $IFDs = $exif->IFDs;
    check("$file byte order", ($bo === 'MM') ? 1 : 2, $exif->byteOrder);
    check("$file IFDs", ['0th', 'Exif', 'Interoperability', 'GPSInfo', '1th'], array_keys($IFDs));
    $t = $IFDs['0th']->tagTable;
    $expect = [0x010E => [2, 15, "synthetic exif\0"], 0x010F => [2, 8, "IO_Exif\0"], 0x0110 => [2, 2, "t\0"],
               0x0112 => [3, 1, [1]], 0x011A => [5, 1, [[72, 1]]], 0x011B => [5, 1, [[144, 2]]],
               0x0128 => [3, 1, [2]], 0x0131 => [2, 9, "make.php\0"], 0x0132 => [2, 20, "2026:11:28 01:02:03\0"],
               0x0213 => [3, 1, [1]], 0xC000 => [1, 2, [0, 255]], 0xC001 => [6, 5, [-128, 127, -1, 0, 1]],
               0xC002 => [8, 2, [-32768, 32767]], 0xC003 => [9, 1, [-1]], 0xC004 => [10, 1, [[-5, 2]]],
               0xC005 => [11, 1, [-0.5]], 0xC006 => [11, 3, [2.0, 0.125, -8.0]], 0xC007 => [12, 2, [1.0e-3, -1.0e10]],
               0xC008 => [4, 2, [0, 0xFFFFFFFF]], 0xC009 => [3, 3, [1, 2, 3]], 0xC00A => [7, 0, ""]];
    foreach ($expect as $tagNo => $e) {
        check(sprintf("$file 0th 0x%04X", $tagNo), $e, [$t[$tagNo]->type, $t[$tagNo]->count, $t[$tagNo]->data]);
    }
    $tagNos = array_merge(array_keys($expect), [0x8769, 0x8825]);
    sort($tagNos);
    check("$file 0th tag order", $tagNos, array_keys($t));
    // pointers and value offsets are from the TIFF header, baseOffset from the start ("Exif\0\0")
    check("$file 0th pointers", [[null, $IFDs['Exif']->baseOffset - 6], [null, $IFDs['GPSInfo']->baseOffset - 6]],
          [[$t[0x8769]->data, $t[0x8769]->offset], [$t[0x8825]->data, $t[0x8825]->offset]]);
    check("$file 0th baseOffset", 6 + 8, $IFDs['0th']->baseOffset);
    check("$file value offset", "IO_Exif\0", substr($data, 6 + $t[0x010F]->offset, 8));
    $e = $IFDs['Exif']->tagTable;
    check("$file Exif", [[[1, 250]], [[28, 10]], [400], "0232", "2026:11:28 01:02:03\0", "\1\2\3\0", [[-1, 3]],
                         "MAKER\0\1", "ASCII\0\0\0comment", "0100", [1], [4, 1, [640]], [3, 1, [480]],
                         $IFDs['Interoperability']->baseOffset - 6],
          [$e[0x829A]->data, $e[0x829D]->data, $e[0x8827]->data, $e[0x9000]->data, $e[0x9003]->data,
           $e[0x9101]->data, $e[0x9204]->data, $e[0x927C]->data, $e[0x9286]->data, $e[0xA000]->data,
           $e[0xA001]->data, [$e[0xA002]->type, $e[0xA002]->count, $e[0xA002]->data],
           [$e[0xA003]->type, $e[0xA003]->count, $e[0xA003]->data], $e[0xA005]->offset]);
    check("$file Interoperability", ["R98\0", "0100"],
          [$IFDs['Interoperability']->tagTable[0x0001]->data, $IFDs['Interoperability']->tagTable[0x0002]->data]);
    $g = $IFDs['GPSInfo']->tagTable;
    check("$file GPSInfo", [[2, 3, 0, 0], "N\0", [[35, 1], [40, 1], [1234, 100]], "E\0", [[139, 1], [45, 1], [0, 1]],
                            [[12, 1], [34, 1], [56, 1]], "2026:11:28\0"],
          [$g[0x0000]->data, $g[0x0001]->data, $g[0x0002]->data, $g[0x0003]->data, $g[0x0004]->data,
           $g[0x0007]->data, $g[0x001D]->data]);
    $t1 = $IFDs['1th']->tagTable;
    check("$file 1th thumbnail", [[6], [[72, 1]], [9], $thumb],
          [$t1[0x0103]->data, $t1[0x011A]->data, $t1[0x0202]->data, substr($data, 6 + $t1[0x0201]->data[0], 9)]);
    check("$file build identical", md5($data), md5($exif->build()));
}
check("MM and II same values", tagValues(parseExif(file_get_contents("$dataDir/all-mm.exif"))),
      tagValues(parseExif(file_get_contents("$dataDir/all-ii.exif"))));

$data = file_get_contents("$dataDir/strips-mm.exif");
$exif = parseExif($data);
$t1 = $exif->IFDs['1th']->tagTable;
check("strips-mm.exif", [['0th', '1th'], [3], [3, 2], [6, 3], "\x10\x20\x30\x40\x50\x60", "\x70\x80\x90"],
      [array_keys($exif->IFDs), $exif->IFDs['0th']->tagTable[0x0112]->data, [$t1[0x0111]->type, $t1[0x0111]->count],
       $t1[0x0117]->data, substr($data, 6 + $t1[0x0111]->data[0], 6), substr($data, 6 + $t1[0x0111]->data[1], 3)]);
check("strips-mm.exif build identical", md5($data), md5($exif->build()));
$data = file_get_contents("$dataDir/0th-only-ii.exif");
$exif = parseExif($data);
check("0th-only-ii.exif", [['0th'], [8], md5($data)],
      [array_keys($exif->IFDs), $exif->IFDs['0th']->tagTable[0x0112]->data, md5($exif->build())]);

// edit: change, add and remove
$exif = parseExif(file_get_contents("$dataDir/all-mm.exif"));
$exif->IFDs['0th']->tagTable[0x0112]->data = [6];
$exif->IFDs['0th']->tagTable[0x013B] = IO_Exif_Tag::Factory(2, 0, null, "", 1);
$exif->IFDs['0th']->tagTable[0x013B]->data = "Artist\0";
$exif->IFDs['Exif']->tagTable[0xA002]->data = [1280];
unset($exif->IFDs['Exif']->tagTable[0x927C]);
$exif->addIFDRemoveList('GPSInfo');
$exif->addIFDRemoveList('Interoperability');
$exif2 = parseExif($exif->build());
check("edit IFDs", ['0th', 'Exif', '1th'], array_keys($exif2->IFDs));
check("edit values", [[6], [2, 7, "Artist\0"], [1280], false, false, false, $thumb],
      [$exif2->IFDs['0th']->tagTable[0x0112]->data,
       [$exif2->IFDs['0th']->tagTable[0x013B]->type, $exif2->IFDs['0th']->tagTable[0x013B]->count,
        $exif2->IFDs['0th']->tagTable[0x013B]->data],
       $exif2->IFDs['Exif']->tagTable[0xA002]->data, isset($exif2->IFDs['Exif']->tagTable[0x927C]),
       isset($exif2->IFDs['0th']->tagTable[0x8825]), isset($exif2->IFDs['Exif']->tagTable[0xA005]),
       substr($exif2->exifData, 6 + $exif2->IFDs['1th']->tagTable[0x0201]->data[0], 9)]);
$exif->addIFDRemoveList('1th');
$exif3 = parseExif($exif->build());
check("edit remove 1th", [['0th', 'Exif'], 0],
      [array_keys($exif3->IFDs), unpack('N', substr($exif3->exifData, $exif3->IFDs['0th']->baseOffset + $exif3->IFDs['0th']->baseSize, 4))[1]]);

// dump(): no warnings / notices with every option
$warnings = [];
set_error_handler(function ($errno, $errstr, $errfile, $errline) use (&$warnings) {
    $warnings []= "$errstr ($errfile:$errline)";
    return true;
});
$dumps = [];
foreach (array_diff($madeFiles, ['truncated.exif']) as $file) {
    foreach ([[], ['name' => true], ['hexdump' => true, 'verbose' => true]] as $opts) {
        list($out, $err) = dumpExif(parseExif(file_get_contents("$dataDir/$file")), $opts);
        $dumps[$file][json_encode($opts)] = $out;
        check("$file dump ".json_encode($opts), [null, 0], [$err, strpos($out, "IFD:0th\n")]);
    }
}
restore_error_handler();
check("dump no warnings", [], $warnings);
$out = $dumps['all-mm.exif']['{"name":true}'];
foreach (["0x0112(Orientation): Type:SHORT Count:1 Data: [0]1\n",
          "0x0110(Model): Type:ASCII Count:2 Data:t\0\n",
          "0xC001((Unknown)): Type:SBYTE Count:5 Offset:",
          " Data: [0]-128 [1]127 [2]-1 [3]0 [4]1\n",
          "0xC002((Unknown)): Type:SSHORT Count:2 Data: [0]-32768 [1]32767\n",
          "0xC005((Unknown)): Type:FLOAT Count:1 Data: [0]-0.5\n",
          "0xC00A((Unknown)): Type:UNDEFINED Count:0 Data:\n",
          "0x8827(PhotographicSensitivity): Type:SHORT Count:1 Data: [0]400\n",
          "0x9000(ExifVersion): Type:UNDEFINED Count:4 Data:0232\n",
          " Data: [0]1/250=0.004\n",
          "0x0000(GPSVersionID): Type:BYTE Count:4 Data: [0]2 [1]3 [2]0 [3]0\n",
          "0x0202(JPEGInterchangeFormatLength): Type:LONG Count:1 Data: [0]9\n"] as $line) {
    check("all-mm.exif dump has ".json_encode($line), 1, substr_count($out, $line));
}
check("all-mm.exif dump IFD names", ['0th', 'Exif', 'Interoperability', 'GPSInfo', '1th'],
      preg_match_all('/^IFD:(.*)$/m', $out, $m) ? $m[1] : null);
check("MM and II dump the same", $dumps['all-mm.exif']['[]'], $dumps['all-ii.exif']['[]']);

// broken input
checkThrows("truncated.exif", '/getData|getUI/', function () use ($dataDir) {
    parseExif(file_get_contents("$dataDir/truncated.exif"));
});
$data = file_get_contents("$dataDir/all-ii.exif");
checkThrows("no Exif header", '/^Unknown head 6 byte/', function () use ($data) { parseExif(substr($data, 6)); });
checkThrows("empty input", '/./', function () { parseExif(""); });
checkThrows("unknown byte order", '/^Unknown byte order: IM/', function () use ($data) { parseExif(substr_replace($data, "IM", 6, 2)); });
checkThrows("unknown TIFF version", '/^Unknown TIFF version:0x2b/', function () use ($data) {
    parseExif(substr_replace($data, pack('v', 0x2B), 8, 2));
});
checkThrows("0th offset beyond the end", '/./', function () use ($data) { parseExif(substr_replace($data, pack('V', 0x7fffffff), 10, 4)); });
checkThrows("1th offset beyond the end", '/./', function () use ($data) {
    parseExif(substr_replace($data, pack('V', strlen($data)), 6 + 8 + 2 + 12 * 23, 4));
});
checkThrows("unknown field type", '/unknown type 13/', function () use ($data) {
    parseExif(substr_replace($data, pack('v', 13), 6 + 8 + 2 + 2, 2));
});
checkThrows("entry count beyond the end", '/./', function () use ($data) { parseExif(substr_replace($data, pack('v', 0xFFFF), 14, 2)); });
checkThrows("Exif pointer beyond the end", '/./', function () use ($data) {
    $pos = strpos($data, pack('vvV', 0x8769, 4, 1));
    parseExif(substr_replace($data, pack('V', 0x10000), $pos + 8, 4));
});
// an IFD after 1th is not read, and build() can not write it
$exif = parseExif($data);
$next = $exif->IFDs['1th']->baseOffset + $exif->IFDs['1th']->baseSize;
$exif = parseExif(substr_replace($data, pack('V', $exif->IFDs['0th']->baseOffset - 6), $next, 4));
check("IFD after 1th", [['0th', 'Exif', 'Interoperability', 'GPSInfo', '1th'], "build: IFD:1th is followed by another IFD (not supported)"],
      [array_keys($exif->IFDs), buildError($exif)]);

// every truncation of the files: an exception or a result, no warnings, no hang
$code = <<<'CODE'
require_once 'IO/Exif.php';
set_error_handler(function ($no, $str, $file, $line) { fwrite(STDERR, "WARNING: $str ($file:$line)\n"); return true; });
$data = file_get_contents($argv[1]);
$lastThrow = -1;
for ($len = 0 ; $len < strlen($data) ; $len++) {
    $exif = new IO_Exif();
    try {
        $exif->parse(substr($data, 0, $len));
    } catch (Exception $e) {
        $lastThrow = $len;
        continue;
    }
    ob_start();
    $exif->dump(['name' => true]);
    ob_end_clean();
    try {
        $exif->build();
    } catch (Exception $e) {
    }
}
echo "lastThrow:$lastThrow\n";
CODE;
foreach (array_diff($madeFiles, ['truncated.exif']) as $file) {
    list($status, $out, $err) = runPHP(['-r', $code, "$dataDir/$file"]);
    check("$file truncations", [0, '', 0], [$status, $err, substr_count($out, "WARNING")]);
    // every IFD and value is needed: any cut before the image data throws
    $exif = parseExif(file_get_contents("$dataDir/$file"));
    $end = 0;
    foreach ($exif->IFDs as $ifd) {
        $end = max($end, $ifd->baseOffset + $ifd->baseSize + 4, $ifd->extendOffset + $ifd->extendSize);
    }
    check("$file truncations before the end of IFDs throw", true,
          preg_match('/lastThrow:(-?\d+)/', $out, $m) && ($end - 1 <= (int) $m[1]));
}

// sample/exifdump.php
foreach (array_merge(array_diff($madeFiles, ['truncated.exif']),
                     glob(dirname(__FILE__).'/../test/*.exif'), glob(dirname(__FILE__).'/../test/*.EXIF')) as $file) {
    $path = (strpos($file, '/') === false) ? "$dataDir/$file" : $file;
    foreach ([[], ['-n'], ['-n', '-h', '-v']] as $opts) {
        list($status, $out, $err) = runPHP(array_merge(['sample/exifdump.php', '-f', $path], $opts));
        check("exifdump ".basename($path)." ".implode(' ', $opts), [0, '', 0], [$status, $err, strpos($out, "IFD:0th\n")]);
    }
}
list($status, $out, $err) = runPHP(['sample/exifdump.php', '-n', '-f', "$dataDir/all-ii.exif"]);
check("exifdump output", $dumps['all-ii.exif']['{"name":true}'], $out);
list($status, $out, $err) = runPHP(['sample/exifdump.php']);
check("exifdump usage", [1, ''], [$status, $out]);
list($status, $out, $err) = runPHP(['sample/exifdump.php', '-f', "$dataDir/truncated.exif"]);
check("exifdump truncated fails", true, $status > 0);

echo "passed: $passed, failed: $failed".PHP_EOL;
exit($failed ? 1 : 0);
