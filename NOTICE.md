# NOTICE

## ianseo-lite

Copyright 2026 e-donkun

ianseo-lite は、本家 ianseo のファイルを含む派生物として、本家と同じ
**Functional Source License, Version 1.1, ALv2 Future License (FSL-1.1-ALv2)** で配布します。
全文はこのフォルダの `LICENSE.txt`(本家 ianseo のものと同一)、または
https://ianseo.net/IanseoLicense-FSL-1.1-ALv2.html を参照してください。

## ianseo

IANSEO is released under «Functional Source License, Version 1.1, ALv2 Future License» (FSL-1.1-ALv2).
This program is free software: you can redistribute it and/or modify it under the terms of the
Functional Source License, Version 1.1, ALv2 Future License.
This program is distributed in the hope that it will be useful, AS IS, WITHOUT WARRANTIES OR
CONDITIONS OF ANY KIND, either express or implied.
You should have received a copy of the License along with this program.
If not, see https://ianseo.net/IanseoLicense-FSL-1.1-ALv2.html.

Copyright 2025 Ianseo

ianseo-lite に含む、本家 ianseo 由来のもの:

| ianseo-lite | 本家 ianseo の元 | 変更 |
|---|---|---|
| `assets/targets/*.svgz` | `Common/Images/Targets/` | なし |
| `assets/lang/en/` | `Common/Languages/en/`(`ISK-App.php`, `en.txt`, `en.png`) | なし |
| `assets/lang/ja/` | `Common/Languages/ja/`(`ISK-App.php`, `ja.txt`, `ja.png`) | `ISK-App.php` の「大会」を「記録会」に(2行) |
| `src/LetterPoint.php` | `Common/Lib/ArrTargets.inc.php` の `$LetterPoint` | 書式のみ |
| `src/TargetFaces.data.php` | `Install/install.sql` の Targets 表 | `tools/gen_targets.php` で必要な的面だけを変換 |

「ianseo」は本家の名称です。ianseo-lite は本家 ianseo の公式なものではなく、本家とは関係なく作られています。

## TCPDF

`lib/tcpdf/` は TCPDF 6.11.2(https://tcpdf.org/)です。
Copyright (C) 2002-2026 Nicola Asuni - Tecnick.com LTD
**GNU Lesser General Public License version 3**(GNU GPL v3 を含む全文は `lib/tcpdf/LICENSE.TXT`)で配布されています。

- TCPDF は PHP のソースのまま同梱しており、利用者は同じ場所のファイルを差し替えて別の版の TCPDF を使えます。
- 変更したファイル: `lib/tcpdf/fonts/kozgopromedium.php`(2026-10-10、文字幅の表 `uni2cid_aj16.php` を読み込む1行を追加。ファイルの先頭に記載)。
- 使わないフォント・ファイルは同梱していません(元の TCPDF からの削除のみ)。
- 小塚ゴシック(Kozuka Gothic)のフォント本体は同梱していません(PDF には埋め込まず、表示する側のフォントを使います)。
