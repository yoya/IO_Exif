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

echo "passed: $passed, failed: $failed".PHP_EOL;
exit($failed ? 1 : 0);
