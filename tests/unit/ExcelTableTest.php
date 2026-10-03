<?php

namespace Tests\Unit;

use App\Libraries\ExcelTable;
use PHPUnit\Framework\TestCase;

final class ExcelTableTest extends TestCase
{
    public function testRendersHeaderAndRowsAsTableCells(): void
    {
        $html = ExcelTable::render(['A', 'B'], [[1, 2], [3, 4]]);

        $this->assertStringContainsString('<th>A</th><th>B</th>', $html);
        $this->assertStringContainsString('<tr><td>1</td><td>2</td></tr>', $html);
        $this->assertStringContainsString('<tr><td>3</td><td>4</td></tr>', $html);
        $this->assertStringContainsString('charset="UTF-8"', $html);
        $this->assertStringStartsWith('<html>', $html);
        $this->assertStringEndsWith('</table></body></html>', $html);
    }

    public function testEscapesHtmlSpecialCharacters(): void
    {
        $html = ExcelTable::render(
            ['Nama'],
            [['<img src=x onerror=alert(1)>'], ['"A" & B']]
        );

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;img', $html);
        $this->assertStringContainsString('&amp;', $html);
        $this->assertStringContainsString('&quot;', $html);
    }

    public function testEmptyRowsStillRendersHeaderRow(): void
    {
        $html = ExcelTable::render(['A'], []);

        $this->assertStringContainsString('<th>A</th>', $html);
        $this->assertStringContainsString('</table>', $html);
    }
}
