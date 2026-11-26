IO_Exif
=======

日本語版: [README.md](README.md)

A library for parsing and dumping Exif (`Exif\0\0` followed by a TIFF structure, as stored in the APP1 segment of a JPEG).
It reads IFD0 (0th), IFD1 (1th) and the Exif, GPS and Interoperability IFDs they point to, and gives the type, count and value of each tag.
It requires IO_Bit. Not limited in use, free to copy and modify under the MIT license.
Please contact me if it does not work in your environment or if you want new features.

# License

MIT License

# Install

```
% composer require yoya/io_exif
```

The `require_once` calls inside the library are resolved through include_path, so put the IO_Exif and IO_Bit directories on include_path (see the examples below).

# Usage

- exifdump.php (dump of the IFDs and tags; `-n` adds tag names)

```
% php -d include_path=vendor/yoya/io_exif:vendor/yoya/io_bit vendor/yoya/io_exif/sample/exifdump.php
Usage: php exifdump.php -f <exif_file> [-n] [-h] [-v]
ex) php exifdump.php -f test.exif -hnv 
% php -d include_path=vendor/yoya/io_exif:vendor/yoya/io_bit vendor/yoya/io_exif/sample/exifdump.php -n -f vendor/yoya/io_exif/test/IMG_0905.exif
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
set_include_path('vendor/yoya/io_exif'.PATH_SEPARATOR.'vendor/yoya/io_bit'.PATH_SEPARATOR.get_include_path());
require_once 'IO/Exif.php';

$exif = new IO_Exif();
$exif->parse(file_get_contents('vendor/yoya/io_exif/test/IMG_0905.exif'));  // data starting with "Exif\0\0"

$tag = $exif->IFDs['0th']->tagTable[0x0110];  // Model
echo $tag->data, PHP_EOL;                     // "iPhone 6 Plus\0"
$tag = $exif->IFDs['Exif']->tagTable[0xA002]; // PixelXDimension
echo $tag->data[0], PHP_EOL;                  // 640
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
- `build()` copies the original bytes IFD by IFD when no IFD was modified.

# Known limitations

- A JPEG file cannot be passed as is. Cut out the content of APP1 (from `Exif\0\0`) and pass that.
- Only the types BYTE, ASCII, SHORT, LONG, RATIONAL, UNDEFINED, SLONG and SRATIONAL are supported. SBYTE, SSHORT, FLOAT, DOUBLE and so on throw an exception, and nothing can be read.
- `build()` is unfinished. It reproduces the input only for simple layouts (`test/IMG_0905.exif`, `test/IMG_1474.exif`).
  It throws when an IFD's out-of-line values come after a later IFD (`test/CA3G0035.EXIF`), and truncates the output when an IFD has no out-of-line values. Writing modified tags is not implemented.
- `exifdump.php` accepts `-h` and `-v`, but they do not change the output.
- Composer autoload alone cannot load it (the `require_once` calls in the library use include_path).

# Test

```
% php tests/run.php
```

# Reference

- http://dsas.blog.klab.org/archives/52123322.html
- http://www.vieas.com/exif23.html
