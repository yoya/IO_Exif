IO_Exif
=======

日本語版: [README.md](README.md)

A library for parsing, dumping and writing Exif (`Exif\0\0` followed by a TIFF structure, as stored in the APP1 segment of a JPEG).
It reads IFD0 (0th), IFD1 (1th) and the Exif, GPS and Interoperability IFDs they point to, and gives the type, count and value of each tag.
It requires IO_Bit. Not limited in use, free to copy and modify under the MIT license.
Please contact me if it does not work in your environment or if you want new features.

# License

MIT License

# Install

```
% composer require yoya/io_exif
```

Load `vendor/autoload.php` and it is ready. Without Composer, put the IO_Exif and IO_Bit directories on include_path.

# Usage

- exifdump.php (dump of the IFDs and tags; `-n` adds tag names)

```
% php vendor/yoya/io_exif/sample/exifdump.php
Usage: php exifdump.php -f <exif_file> [-n] [-h] [-v]
ex) php exifdump.php -f test.exif -hnv 
% php vendor/yoya/io_exif/sample/exifdump.php -n -f vendor/yoya/io_exif/test/IMG_0905.exif
IFD:0th
    BaseOffset:14 BaseSize:110
    ExtendOffset:128 ExtendSize:70
    TagTable:(count=9)
        0x010F(Make): Type:ASCII Count:6 Offset:122 Data:Apple 
        0x0110(Model): Type:ASCII Count:14 Offset:128 Data:iPhone 6 Plus 
        0x0112(Orientation): Type:SHORT Count:1 Data: [0]1
        0x011A(XResolution): Type:RATIONAL Count:1 Offset:142 Data: [0]72/1=72
        0x011B(YResolution): Type:RATIONAL Count:1 Offset:150 Data: [0]72/1=72
        0x0128(ResolutionUnit): Type:SHORT Count:1 Data: [0]2
        0x0131(Software): Type:ASCII Count:13 Offset:158 Data:Photos 1.0.1 
        0x0132(DateTime): Type:ASCII Count:20 Offset:172 Data:2015:10:03 10:13:51 
        0x8769(Exif IFD Pointer): Type:LONG Count:1 Offset:192
IFD:Exif
    BaseOffset:198 BaseSize:110
    ExtendOffset:312 ExtendSize:40
    TagTable:(count=9)
        0x9000(ExifVersion): Type:UNDEFINED Count:4 Data:0221
        0x9003(DateTimeOriginal): Type:ASCII Count:20 Offset:306 Data:2015:10:03 10:13:51 
        0x9004(DateTimeDigitized): Type:ASCII Count:20 Offset:326 Data:2015:10:03 10:13:51 
        0x9101(ComponentsConfiguration): Type:UNDEFINED Count:4 Data: 
        0xA000(FlashpixVersion): Type:UNDEFINED Count:4 Data:0100
        0xA001(ColorSpace): Type:SHORT Count:1 Data: [0]1
        0xA002(PixelXDimension): Type:LONG Count:1 Data: [0]640
        0xA003(PixelYDimension): Type:LONG Count:1 Data: [0]480
        0xA406(SceneCaptureType): Type:SHORT Count:1 Data: [0]0
```

- From PHP

```php
require 'vendor/autoload.php';

$exif = new IO_Exif();
$exif->parse(file_get_contents('vendor/yoya/io_exif/test/IMG_0905.exif'));  // data starting with "Exif\0\0"

$tag = $exif->IFDs['0th']->tagTable[0x0110];  // Model
echo $tag->data, PHP_EOL;                     // "iPhone 6 Plus\0"
$tag = $exif->IFDs['Exif']->tagTable[0xA002]; // PixelXDimension
echo $tag->data[0], PHP_EOL;                  // 640

$exif->IFDs['0th']->tagTable[0x0112]->data = [6];  // change Orientation
file_put_contents('out.exif', $exif->build());     // data starting with "Exif\0\0"
```

# Design

| Class | Role |
|---|---|
| `IO_Exif` | The whole Exif. `parse()` checks the header (`Exif\0\0`, byte order `MM`/`II`, TIFF version 0x2A), reads the IFDs and builds `IFDs` (IFD name → `IO_Exif_IFD`). `dump()` / `build()` |
| `IO_Exif_IFD` | One IFD. It holds `tagTable` (tag ID → `IO_Exif_Tag`), the position and size of the IFD itself (`baseOffset`, `baseSize`) and the range of the values stored outside the IFD (`extendOffset`, `extendSize`) |
| `IO_Exif_Tag` | One tag. It holds the type (`type`), count (`count`), value position (`offset`) and value (`data`). The tag name table (`getTagName()`) is here too |
| `IO_Exif_Bit` | Extends `IO_Bit`. Reads and writes BYTE / SHORT / LONG / RATIONAL and so on in the given byte order |

- `data` is the raw bytes for ASCII and UNDEFINED, and an array of values otherwise (RATIONAL is `[numerator, denominator]`).
- When it finds a pointer to the Exif, GPS or Interoperability IFD (0x8769, 0x8825, 0xA005), it reads that IFD too. `IFDs` is sorted by position.
  An IFD pointer that loops back to one of its parents throws an exception.
- Offsets: `BaseOffset` / `ExtendOffset` count from the start of the data (including `Exif\0\0`), while a tag's `Offset` counts from the TIFF header (`MM`/`II`).
- `build()` writes the IFDs in the order of `IFDs`, each followed by its out-of-line values, then copies the thumbnail (JPEGInterchangeFormat) and strips, and renumbers the offsets.
  Tags are sorted by number and counts follow `data`, so a changed `data` is written. Values and IFDs are aligned to 2 bytes.
  IFDs named with `addIFDRemoveList('GPSInfo')` and so on are left out, together with the tags pointing to them and the IFDs only they point to (0th cannot be removed).

# Known limitations

- A JPEG file cannot be passed as is. Cut out the content of APP1 (from `Exif\0\0`) and pass that.
- The types are TIFF 6.0 types 1 to 12 (BYTE, ASCII, SHORT, LONG, RATIONAL, SBYTE, UNDEFINED, SSHORT, SLONG, SRATIONAL, FLOAT, DOUBLE). Any other type throws an exception, and nothing can be read.
- `build()` does not reproduce the input bytes; it lays the data out again (gaps disappear and offsets change).
  - MakerNote moves as raw bytes, so a MakerNote format that holds offsets from the TIFF header inside it ends up with wrong offsets.
  - These cannot be written and throw an exception: a pointer to an IFD that was not read (such as SubIFDs), an IFD after IFD1, FreeOffsets, the old JPEG tables (JPEGQTables and so on), ExtraCameraProfiles, and offsets outside the data.
- `exifdump.php` accepts `-h` and `-v`, but they do not change the output.

# Test

```
% php tests/run.php
```

# Reference

- http://dsas.blog.klab.org/archives/52123322.html
- http://www.vieas.com/exif23.html
