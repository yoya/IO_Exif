<?php

/*
 * IO_Exif class - v2.1
 * 2016/5/22- (c) yoya@awm.jp
 */

require_once dirname(__FILE__).'/Exif/Bit.php';
require_once dirname(__FILE__).'/Exif/Tag.php';
require_once dirname(__FILE__).'/Exif/IFD.php';

class IO_Exif {
    var $exifData = null;
    var $byteOrder = null; // 1:Big Endian(MM), 2:LittleEndian(II)
    var $IFDs = null;
    var $IFDRemoveList = array();
    const IFD_OFFSET_BASE = 6;
    function parse($exifData) {
        $reader = new IO_Bit();
        $reader->input($exifData);
        $this->exifData  = $exifData;
        $bit = new IO_Exif_Bit();
        $bit->input($exifData);
        // Head Binary Check
        $head6 = $bit->getData(6);
        if ($head6 != "Exif\0\0") {
            throw new Exception("Unknown head 6 byte: $head6");
            return false;
        }
        $byteOrderId = $bit->getData(2);
        switch ($byteOrderId) {
        case "MM": // Big Endian
            $this->byteOrder = 1;
            break;
        case "II": // Little Endian
            $this->byteOrder = 2;
            break;
        default:
            throw new Exception("Unknown byte order: $byteOrderId");
        }
        $bit->setByteOrder($this->byteOrder);
        $tiffVersion = $bit->getSHORT();
        if ($tiffVersion !== 0x002A) {
            throw new Exception("Unknown TIFF version:0x".dechex($tiffVersion));
        }
        $this->IFDs = array();
        $IFD0thOffset = $bit->getLONG();
        $ifdTable = IO_Exif_IFD::Factory($bit, self::IFD_OFFSET_BASE + $IFD0thOffset, "0th");
        $this->IFDs += $ifdTable;
        $IFD1thOffset = $bit->getLONG();
        if ($IFD1thOffset > 0) {
            $ifdTable = IO_Exif_IFD::Factory($bit, self::IFD_OFFSET_BASE + $IFD1thOffset, "1th");
            $this->IFDs += $ifdTable;
        }
        IO_Exif_IFD::sortIFDsByBaseOffset($this->IFDs);
    }
    /*
     * Writes "Exif\0\0" + TIFF: header, the IFDs in the parsed order each
     * followed by its out-of-line values, then the image data (JPEG
     * thumbnail, strips), with all offsets renumbered.
     * Throws for data it can not carry over.
     */
    function build() {
        switch ($this->byteOrder) {
        case 1:
            $byteOrderId = "MM";
            break;
        case 2:
            $byteOrderId = "II";
            break;
        default:
            throw new Exception("Unknown byte order: {$this->byteOrder}");
        }
        $IFDNameTable = IO_Exif_IFD::getIFDNameTable();
        // tags with offsets to data => tags with their byte counts
        $dataTagTable = [0x0111 => 0x0117,  // StripOffsets
                         0x0144 => 0x0145,  // TileOffsets
                         0x0201 => 0x0202]; // JPEGInterchangeFormat
        // tags with offsets to data that build() does not know the size of
        $unsupportedTagTable = [0x0120 => 'FreeOffsets',
                                0x0207 => 'JPEGQTables',
                                0x0208 => 'JPEGDCTables',
                                0x0209 => 'JPEGACTables',
                                0xC6F5 => 'ExtraCameraProfiles'];
        $ifdNameByOffset = [];
        foreach ($this->IFDs as $ifdName => $ifd) {
            $ifdNameByOffset[$ifd->baseOffset - self::IFD_OFFSET_BASE] = $ifdName;
        }
        $src = new IO_Exif_Bit();
        $src->input($this->exifData);
        $src->setByteOrder($this->byteOrder);
        $srcSize = strlen($this->exifData) - self::IFD_OFFSET_BASE; // TIFF part
        /*
         * tags to write, and the IFDs / data that offset tags refer to
         */
        $tagTables = [];  // ifdName => [tagNo => tag (copy)]
        $refs = [];  // ifdName => [tagNo => ['ifd', [ifdName or null, ...]] or ['data', [bytes, ...]]]
        if (isset($this->IFDRemoveList["0th"])) {
            throw new Exception("build: IFD:0th can not be removed");
        }
        // IFD chain (0th, 1th) without the removed ones
        $chainNames = array_values(array_diff(array_intersect(["0th", "1th"], array_keys($this->IFDs)),
                                              array_keys($this->IFDRemoveList)));
        // the IFDs that are reachable from the chain and not removed
        $queue = $chainNames;
        while (count($queue) > 0) {
            $ifdName = array_shift($queue);
            if (isset($tagTables[$ifdName])) {
                continue;
            }
            $ifd = $this->IFDs[$ifdName];
            $tagTable = [];
            $refs[$ifdName] = [];
            foreach ($ifd->tagTable as $tagNo => $tag) {
                $tag = clone $tag;
                if (is_string($tag->data)) {
                    $tag->count = strlen($tag->data);
                } else if (is_array($tag->data)) {
                    $tag->count = count($tag->data);
                }
                $tagTable[$tagNo] = $tag;
            }
            ksort($tagTable);
            foreach ($tagTable as $tagNo => $tag) {
                $tagHex = sprintf("0x%04X", $tagNo);
                if (isset($IFDNameTable[$tagNo]) || ($tagNo === 0x014A) || ($tag->type === 13)) {
                    if (isset($IFDNameTable[$tagNo])) {
                        if ($tag->data !== null) {
                            throw new Exception("build: IFD pointer $tagHex in IFD:$ifdName has count {$tag->count}");
                        }
                        $offsets = [$tag->offset];
                    } else if (is_array($tag->data)) {
                        $offsets = $tag->data;
                    } else {
                        throw new Exception("build: IFD pointer $tagHex in IFD:$ifdName has type {$tag->type}");
                    }
                    $names = [];
                    foreach ($offsets as $offset) {
                        if ($offset == 0) {
                            $names []= null;
                        } else if (isset($ifdNameByOffset[$offset])) {
                            if (! isset($this->IFDRemoveList[$ifdNameByOffset[$offset]])) {
                                $names []= $ifdNameByOffset[$offset];
                                $queue []= $ifdNameByOffset[$offset];
                            }
                        } else {
                            throw new Exception("build: $tagHex in IFD:$ifdName points to an IFD at offset $offset that was not parsed");
                        }
                    }
                    if (count($names) === 0) { // all pointed IFDs are removed
                        unset($tagTable[$tagNo]);
                        continue;
                    }
                    $tag->count = count($names);
                    $refs[$ifdName][$tagNo] = ['ifd', $names];
                } else if (isset($dataTagTable[$tagNo])) {
                    $countTagNo = $dataTagTable[$tagNo];
                    if ((! is_array($tag->data)) || (! isset($tagTable[$countTagNo])) ||
                        (! is_array($tagTable[$countTagNo]->data)) ||
                        (count($tagTable[$countTagNo]->data) !== count($tag->data))) {
                        throw new Exception(sprintf("build: $tagHex in IFD:$ifdName needs 0x%04X with the same count", $countTagNo));
                    }
                    $dataList = [];
                    foreach ($tag->data as $i => $offset) {
                        $length = $tagTable[$countTagNo]->data[$i];
                        if ($srcSize < $offset + $length) {
                            throw new Exception("build: $tagHex in IFD:$ifdName refers beyond the end of data (offset:$offset length:$length)");
                        }
                        $dataList []= substr($this->exifData, self::IFD_OFFSET_BASE + $offset, $length);
                    }
                    $refs[$ifdName][$tagNo] = ['data', $dataList];
                } else if (isset($unsupportedTagTable[$tagNo])) {
                    throw new Exception("build: $tagHex({$unsupportedTagTable[$tagNo]}) in IFD:$ifdName is not supported");
                }
            }
            if (! in_array($ifdName, array_merge(["0th"], array_values($IFDNameTable)), true)) {
                $src->setByteOffset($ifd->baseOffset + $ifd->baseSize);
                if ($src->getLONG() !== 0) {
                    throw new Exception("build: IFD:$ifdName is followed by another IFD (not supported)");
                }
            }
            $tagTables[$ifdName] = $tagTable;
        }
        $orderedTagTables = []; // in the parsed order
        foreach ($this->IFDs as $ifdName => $ifd) {
            if (isset($tagTables[$ifdName])) {
                $orderedTagTables[$ifdName] = $tagTables[$ifdName];
            }
        }
        $tagTables = $orderedTagTables;
        /*
         * layout (again when a SHORT offset tag needs LONG)
         */
        do {
            $retry = false;
            $offset = 8;
            $ifdOffsets = [];
            $valueOffsets = [];
            foreach ($tagTables as $ifdName => $tagTable) {
                $offset += $offset % 2;
                $ifdOffsets[$ifdName] = $offset;
                $offset += 2 + 12 * count($tagTable) + 4;
                $valueOffsets[$ifdName] = [];
                foreach ($tagTable as $tagNo => $tag) {
                    if ($tag->data !== null) {
                        $size = IO_Exif_Tag::getDataSize($tag->type, $tag->count);
                        if ($size > 4) {
                            $offset += $offset % 2;
                            $valueOffsets[$ifdName][$tagNo] = $offset;
                            $offset += $size;
                        }
                    }
                }
            }
            $dataOffsets = [];
            foreach ($refs as $ifdName => $ifdRefs) {
                foreach ($ifdRefs as $tagNo => $ref) {
                    if ($ref[0] === 'data') {
                        $dataOffsets[$ifdName][$tagNo] = [];
                        foreach ($ref[1] as $data) {
                            $offset += $offset % 2;
                            $dataOffsets[$ifdName][$tagNo] []= $offset;
                            $offset += strlen($data);
                        }
                        $tag = $tagTables[$ifdName][$tagNo];
                        if (($tag->type === 3) && (0xFFFF < max($dataOffsets[$ifdName][$tagNo]))) { // SHORT
                            $tag->type = 4; // LONG
                            $retry = true;
                        }
                    }
                }
            }
        } while ($retry);
        /*
         * write
         */
        $bit = new IO_Exif_Bit();
        $bit->setByteOrder($this->byteOrder);
        $bit->putData($byteOrderId);
        $bit->putSHORT(0x002A); // TIFF version
        $bit->putLONG($ifdOffsets["0th"]);
        foreach ($tagTables as $ifdName => $tagTable) {
            foreach ($refs[$ifdName] as $tagNo => $ref) {
                $tag = $tagTable[$tagNo];
                if ($ref[0] === 'ifd') {
                    $values = [];
                    foreach ($ref[1] as $name) {
                        $values []= ($name === null) ? 0 : $ifdOffsets[$name];
                    }
                } else {
                    $values = $dataOffsets[$ifdName][$tagNo];
                }
                if ($tag->data === null) {
                    $tag->offset = $values[0];
                } else {
                    $tag->data = $values;
                }
            }
            $bit->alignNBytes(2);
            if ($bit->getByteOffset() !== $ifdOffsets[$ifdName]) {
                throw new Exception("build: internal error (IFD:$ifdName offset)");
            }
            $bit->putSHORT(count($tagTable));
            $values = [];
            foreach ($tagTable as $tagNo => $tag) {
                $bit->putSHORT($tagNo);
                $bit->putSHORT($tag->type);
                $bit->putLONG($tag->count);
                if ($tag->data === null) { // IFD pointer
                    $bit->putLONG($tag->offset);
                } else {
                    $data = $tag->buildData($this->byteOrder);
                    if (isset($valueOffsets[$ifdName][$tagNo])) {
                        $bit->putLONG($valueOffsets[$ifdName][$tagNo]);
                        $values[$tagNo] = $data;
                    } else {
                        $bit->putData(str_pad($data, 4, "\0"));
                    }
                }
            }
            $bit->putLONG((($ifdName === "0th") && isset($ifdOffsets["1th"])) ? $ifdOffsets["1th"] : 0); // IFD1 if kept
            foreach ($values as $tagNo => $data) {
                $bit->alignNBytes(2);
                if ($bit->getByteOffset() !== $valueOffsets[$ifdName][$tagNo]) {
                    throw new Exception("build: internal error (IFD:$ifdName value offset)");
                }
                $bit->putData($data);
            }
        }
        foreach ($refs as $ifdName => $ifdRefs) {
            foreach ($ifdRefs as $tagNo => $ref) {
                if ($ref[0] === 'data') {
                    foreach ($ref[1] as $i => $data) {
                        $bit->alignNBytes(2);
                        if ($bit->getByteOffset() !== $dataOffsets[$ifdName][$tagNo][$i]) {
                            throw new Exception("build: internal error (IFD:$ifdName data offset)");
                        }
                        $bit->putData($data);
                    }
                }
            }
        }
        return "Exif\0\0".$bit->output();
    }
    function dump($opts = array()) {
        foreach ($this->IFDs as $ifdName => $offsetTable) {
            echo "IFD:$ifdName".PHP_EOL;
            $opts += ['indent' => 1];
            $offsetTable->dump($opts);
        }
    }
    function addIFDRemoveList(string $ifdName) {
        assert(is_string($ifdName));
        $this->IFDRemoveList[$ifdName] = true;
    }
}





