# NOTICE

## ArcRec

Copyright (C) 2026 e-donkun(Jun Suzuki)

ArcRec is free software: you can redistribute it and/or modify it under the terms of the
GNU General Public License as published by the Free Software Foundation, version 3.
This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
See the GNU General Public License (`LICENSE.txt`) for more details.

ArcRec は、ianseo 2023-04-01 版(GNU GPL v3)のファイルを含む派生物として、**GNU GPL v3** で配布します。
全文は `LICENSE.txt` です。

## ianseo

ianseo(<https://www.ianseo.net>)の 2023-04-01 版(`Ianseo_20230401.zip`)は GNU GPL v3 で配布されていました。
ArcRec には、その版の次のファイルを含みます(本家の作者・著作権者に帰属します)。

| ArcRec | ianseo 2023-04-01 版の元 | 変更 |
|---|---|---|
| `assets/targets/{1,2,3,4,5,9,10}.svgz` | `Common/Images/Targets/{1,2,3,4,5,9,10}.svg` | gzip で圧縮(2026-10-10) |
| `src/LetterPoint.php` | `Common/Lib/ArrTargets.inc.php` の `$LetterPoint`(A〜L) | 必要な文字だけを抜き出し、書式を変更(2026-10-10) |
| `src/TargetFaces.data.php` | `Install/install.sql` の Targets 表 | `tools/gen_targets.php` で必要な的面だけを変換(2026-10-10) |

端末アプリ(ScorekeeperNG)用の言語ファイル(`ISK-App.php`)は持っていません(端末からの言語の要求には空で返し、アプリ内蔵の文言を使います)。

## TCPDF

`lib/tcpdf/` は TCPDF 6.11.2(<https://tcpdf.org/>)です。
Copyright (C) 2002-2026 Nicola Asuni - Tecnick.com LTD
**GNU Lesser General Public License version 3**(GNU GPL v3 を含む全文は `lib/tcpdf/LICENSE.TXT`)で配布されています。

- TCPDF は PHP のソースのまま同梱しており、利用者は同じ場所のファイルを差し替えて別の版の TCPDF を使えます。
- 変更したファイル: `lib/tcpdf/fonts/kozgopromedium.php`(2026-10-10、文字幅の表 `uni2cid_aj16.php` を読み込む1行を追加。ファイルの先頭に記載)。
- 使わないフォント・ファイルは同梱していません(元の TCPDF からの削除のみ)。
- 小塚ゴシック(Kozuka Gothic)のフォント本体は同梱していません(PDF には埋め込まず、表示する側のフォントを使います)。
