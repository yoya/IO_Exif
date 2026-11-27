IO_Exif
=======

English version: [README.en.md](README.en.md)

Exif (JPEG の APP1 に入る `Exif\0\0` + TIFF 構造) を解釈・ダンプ・書き出しする為のライブラリです。
IFD0 (0th)、IFD1 (1th) と、そこから指される Exif・GPS・Interoperability の IFD を読み、タグごとの型・個数・値を取り出します。
IO_Bit が必要です。利用に制限はかけません。コピーも改変もご自由にどうぞ。MIT ライセンスにしました。
自分の環境だと動かないとか、機能が欲しいとかいった要望があれば連絡下さい。

# License

MIT License

# Install

```
% composer require yoya/io_exif
```

`vendor/autoload.php` を読み込めば使えます。Composer を使わない場合は、IO_Exif と IO_Bit のディレクトリを include_path に入れます。

# Usage

- exifdump.php (IFD とタグのダンプ。`-n` でタグ名を付ける)

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

- PHP から使う

```php
require 'vendor/autoload.php';

$exif = new IO_Exif();
$exif->parse(file_get_contents('vendor/yoya/io_exif/test/IMG_0905.exif'));  // "Exif\0\0" から始まるデータ

$tag = $exif->IFDs['0th']->tagTable[0x0110];  // Model
echo $tag->data, PHP_EOL;                     // "iPhone 6 Plus\0"
$tag = $exif->IFDs['Exif']->tagTable[0xA002]; // PixelXDimension
echo $tag->data[0], PHP_EOL;                  // 640

$exif->IFDs['0th']->tagTable[0x0112]->data = [6];  // Orientation を変える
file_put_contents('out.exif', $exif->build());     // "Exif\0\0" から始まるデータ
```

# 設計概要

| クラス | 役割 |
|---|---|
| `IO_Exif` | Exif 全体。`parse()` でヘッダ (`Exif\0\0`、バイトオーダー `MM`/`II`、TIFF バージョン 0x2A) を確かめ、IFD を読んで `IFDs` (IFD 名 → `IO_Exif_IFD`) を作る。`dump()` / `build()` |
| `IO_Exif_IFD` | IFD 1 個。`tagTable` (タグ ID → `IO_Exif_Tag`) と、IFD 本体の位置・大きさ (`baseOffset`, `baseSize`)、IFD の外に置かれた値の範囲 (`extendOffset`, `extendSize`) を持つ |
| `IO_Exif_Tag` | タグ 1 個。型 (`type`)、個数 (`count`)、値の位置 (`offset`)、値 (`data`) を持つ。タグ名の表 (`getTagName()`) もここ |
| `IO_Exif_Bit` | `IO_Bit` の拡張。バイトオーダーに従って BYTE / SHORT / LONG / RATIONAL などを読み書きする |

- `data` は、ASCII と UNDEFINED ならバイト列のまま、それ以外は値の配列 (RATIONAL は `[分子, 分母]`)。
- Exif・GPS・Interoperability の IFD へのポインタ (0x8769, 0x8825, 0xA005) を見つけると、その IFD も読む。`IFDs` は位置の順に並ぶ。
  IFD のポインタが自分の親を指してループしていると例外を投げる。
- 位置の基準: `BaseOffset` / `ExtendOffset` はデータの先頭 (`Exif\0\0` を含む) から、タグの `Offset` は TIFF ヘッダ (`MM`/`II`) からの位置。
- `build()` は、`IFDs` の並び順に IFD とその外の値を詰めて書き、最後にサムネイル (JPEGInterchangeFormat) とストリップを写して、オフセットを付け直す。
  タグは番号順に並べ、個数は `data` から決める。`data` を書き換えればその値が書き出される。値と IFD は 2 バイト境界に揃える。
  `addIFDRemoveList('GPSInfo')` などで指定した IFD は、それを指すタグ、それからしか指されない IFD と一緒に書き出さない (0th は消せない)。

# 既知の制限

- JPEG ファイルはそのまま渡せない。APP1 の中身 (`Exif\0\0` から) を切り出して渡す。
- 型は TIFF 6.0 の 1〜12 (BYTE, ASCII, SHORT, LONG, RATIONAL, SBYTE, UNDEFINED, SSHORT, SLONG, SRATIONAL, FLOAT, DOUBLE)。それ以外の型があると例外になり、全体を読めない。
- `build()` は元のバイト列をそのまま再現するのではなく、配置を詰め直す (隙間は無くなり、オフセットは変わる)。
  - MakerNote はバイト列のまま別の位置へ移るので、TIFF ヘッダからのオフセットを中に持つ形式の MakerNote は、中のオフセットが合わなくなる。
  - 次のものは書き出せず例外になる: 読んでいない IFD を指すポインタ (SubIFDs など)、IFD1 の後に続く IFD、FreeOffsets、旧 JPEG の表 (JPEGQTables など)、ExtraCameraProfiles、データの外を指すオフセット。
- `exifdump.php` の `-h` と `-v` は受け付けるが、出力は変わらない。

# Test

```
% php tests/run.php
```

# Reference

- http://dsas.blog.klab.org/archives/52123322.html
- http://www.vieas.com/exif23.html
