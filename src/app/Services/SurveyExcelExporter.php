<?php

namespace App\Services;

use App\Models\Survey;
use RuntimeException;
use ZipArchive;

class SurveyExcelExporter
{
    /** @param array<int, int> $skuIds */
    public function export(Survey $survey, array $skuIds = []): string
    {
        $survey->loadMissing('products.skus');
        $allowedIds = array_fill_keys($skuIds, true);
        $rows = [[
            '品番', 'ブランド', 'カテゴリ', '親ASIN', 'SKU', 'ASIN', 'TQ品番', 'TQカラーNo', 'TQサイズ',
            'Amazon:自社', 'Amazon:FBA', 'BOSS:自社', 'BOSS:RFC', 'フリー在庫', 'ECストック', '品番メモ', 'SKUメモ',
        ]];

        foreach ($survey->products as $product) {
            foreach ($product->skus as $sku) {
                if ($skuIds !== [] && ! isset($allowedIds[$sku->getKey()])) {
                    continue;
                }
                $rows[] = [
                    $product->product_code,
                    $product->brand,
                    $product->category,
                    $product->parent_asin ?? '',
                    $sku->sku_code,
                    $sku->child_asin ?? '',
                    $sku->tq_item_no,
                    $sku->tq_color_no,
                    $sku->tq_size,
                    $sku->amazon_own_stock,
                    $sku->amazon_fba_stock,
                    $sku->boss_own_stock,
                    $sku->boss_rfc_stock,
                    $sku->free_stock,
                    $sku->ec_stock,
                    $product->memo ?? '',
                    $sku->memo ?? '',
                ];
            }
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'zsv-xlsx-');
        if ($temporaryPath === false) {
            throw new RuntimeException('Excelファイルを作成できませんでした。');
        }

        $zip = new ZipArchive;
        if ($zip->open($temporaryPath, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Excelファイルを作成できませんでした。');
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->rootRelationships());
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationships());
        $zip->addFromString('xl/styles.xml', $this->styles());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->worksheet($rows));
        $zip->close();

        return $temporaryPath;
    }

    /** @param array<int, array<int, string|int>> $rows */
    private function worksheet(array $rows): string
    {
        $xmlRows = [];
        foreach ($rows as $rowIndex => $row) {
            $cells = [];
            foreach ($row as $columnIndex => $value) {
                $reference = $this->columnName($columnIndex + 1).($rowIndex + 1);
                if (is_int($value)) {
                    $cells[] = '<c r="'.$reference.'"><v>'.$value.'</v></c>';
                } else {
                    $style = $rowIndex === 0 ? ' s="1"' : '';
                    $cells[] = '<c r="'.$reference.'" t="inlineStr"'.$style.'><is><t xml:space="preserve">'.$this->escape($value).'</t></is></c>';
                }
            }
            $xmlRows[] = '<row r="'.($rowIndex + 1).'">'.implode('', $cells).'</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            .'<cols><col min="1" max="17" width="16" customWidth="1"/></cols>'
            .'<sheetData>'.implode('', $xmlRows).'</sheetData><autoFilter ref="A1:Q1"/></worksheet>';
    }

    private function columnName(int $number): string
    {
        $name = '';
        while ($number > 0) {
            $number--;
            $name = chr(65 + ($number % 26)).$name;
            $number = intdiv($number, 26);
        }

        return $name;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>';
    }

    private function rootRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
    }

    private function workbook(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="在庫調査結果" sheetId="1" r:id="rId1"/></sheets></workbook>';
    }

    private function workbookRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/><color rgb="FFFFFFFF"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF334155"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs></styleSheet>';
    }
}
