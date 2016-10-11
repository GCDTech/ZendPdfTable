<?php

namespace ZendPdfTable;

use ZendPdf\Font;

class Table
{
    public $columnWidths = [];

    public $columnAlignments = [];
    public $headerAlignments = [];

    public $hasHeader = true;
    public $headerFont = Font::FONT_HELVETICA_BOLD;
    public $headerFontSize = 8;
    public $headerFontColour = "#000000";
    public $repeatHeaderRowNum = 0;

    public $textFont = Font::FONT_HELVETICA;
    public $textFontSize = 8;
    public $textFontColour = "#000000";

    public $textFonts = [];
    public $textFontSizes = [];
    public $textFontColours = [];

    public $horizontalGridLines = true;
    public $verticalGridLines = false;
    public $outerBoxLines = true;
    public $outerBoxRadius = 0;
    public $overrideHorizontalGridLine = [];

    public $gridLineWidth = 1;
    public $gridLineColour = "#000000";
    public $outerBoxLineWidth = 1;
    public $outerBoxLineColour = "#000000";

    public $content = [];

    public $cellHorizontalPadding = 3;
    public $cellVerticalPadding = 2;
    public $overrideVerticalPadding = [];

    public $renderedHeight;
    public $renderedLastPageHeight;

    public $marginTop = 0;
    public $marginBottom = 0;

    private $y = 0;

    const SPANNED_COLUMN = "0844a4ba9f7e24f20884bf5f92f5441f";

    public function render(Page $page, $x, $y, $width, &$yCurrent)
    {
        $this->y = $y;

        $pages = [$page];

        if (is_object($this->headerFont)) {
            $headerFont = $this->headerFont;
        } elseif (file_exists($this->headerFont)) {
            $headerFont = Font::fontWithPath($this->headerFont);
        } else {
            $headerFont = Font::fontWithName($this->headerFont);
        }

        if (is_object($this->textFont)) {
            $textFont = $this->textFont;
        } elseif (file_exists($this->textFont)) {
            $textFont = Font::fontWithPath($this->textFont);
        } else {
            $textFont = Font::fontWithName($this->textFont);
        }

        $pageWidth = $page->getWidth(true) - $x;

        if (strpos($width, "%")) {
            $width = str_replace("%", "", $width);
            $width = ($pageWidth / 100) * $width;
        } elseif (!is_numeric($width)) {
            $width = null;
        } elseif ($width < 0) {
            $width = $pageWidth + $width;
        }

        $minWidths = [];
        $maxWidths = [];


        $rowNum = 0;
        foreach ($this->content as $row) {
            if (in_array(self::SPANNED_COLUMN, $row)) {
                continue;
            }

            $colNum = 0;

            foreach ($row as $cell) {
                if (isset($this->columnWidths[$colNum]) && $this->columnWidths[$colNum]) {
                    $minWidths[$colNum][$rowNum] = $this->columnWidths[$colNum];
                    $maxWidths[$colNum][$rowNum] = $this->columnWidths[$colNum];
                    $colNum++;
                    continue;
                }

                if ($rowNum == 0 && $this->hasHeader) {
                    $dimensions = $page->getTextWidths($cell, $headerFont, $this->headerFontSize);
                } else {
                    $dimensions = $page->getTextWidths(
                        $cell,
                        (isset($this->textFonts[$rowNum][$colNum]) ? $this->textFonts[$rowNum][$colNum] : $textFont),
                        (isset($this->textFontSizes[$rowNum][$colNum]) ? $this->textFontSizes[$rowNum][$colNum] : $this->textFontSize)
                    );
                }
                $maxWidths[$colNum][$rowNum] = $dimensions['normal_width'];
                $minWidths[$colNum][$rowNum] = $dimensions['resized_width'];

                $colNum++;
            }

            $rowNum++;
        }

        $numCols = count($minWidths);
        $extraWidthPerCell = ($this->cellHorizontalPadding * 2) + ($this->verticalGridLines ? $this->gridLineWidth : 0);
        $extraWidth = (($extraWidthPerCell * $numCols) - ($this->verticalGridLines ? $this->gridLineWidth : 0)) + ($this->outerBoxLines ? $this->outerBoxLineWidth * 2 : 0);

        for ($colNum = 0; $colNum < $numCols; $colNum++) {
            $maxWidths[$colNum] = max($maxWidths[$colNum]) + $extraWidthPerCell;
            $minWidths[$colNum] = max($minWidths[$colNum]) + $extraWidthPerCell;
        }

        // Calculate flowing table width if none is specified - this will be either the unwrapped
        // text widths of all the columns combined or the page width (whichever is highest).
        $maxWidth = array_sum($maxWidths);
        if ($width == null) {
            $width = $maxWidth + $extraWidth;
            if ($width > $pageWidth) {
                $width = $pageWidth;
            }
        }

        // If the table will be too wide for the page, set it to the minimum width. If the minimum
        // width is narrower than the page, set the table width to the page width.
        $minWidth = array_sum($minWidths) + $extraWidth;
        if ($width > $pageWidth) {
            $width = $minWidth + $extraWidth;
            if ($width < $pageWidth) {
                $width = $pageWidth;
            }
        }

        $contentWidth = $width - $extraWidth;

        $colWidths = [];
        if ($contentWidth == $minWidth) {
            $colWidths = $minWidths;
        } elseif ($contentWidth == $maxWidth) {
            $colWidths = $maxWidths;
        } else {
            $fixedWidthColumns = [];
            $relativeWidths = [];
            for ($colNum = 0; $colNum < $numCols; $colNum++) {
                $relativeWidths[$colNum] = $maxWidths[$colNum] / $maxWidth;
                if (isset($this->columnWidths[$colNum])) {
                    $fixedWidthColumns[] = $colNum;
                }
            }

            for ($colNum = 0; $colNum < $numCols; $colNum++) {
                $colWidths[$colNum] = $contentWidth * $relativeWidths[$colNum];
            }

            $lockedWidthColumns = $fixedWidthColumns;
            $changesMade = true;
            while ($changesMade) {
                $changesMade = false;
                for ($colNum = 0; $colNum < $numCols; $colNum++) {
                    if ($colWidths[$colNum] < $minWidths[$colNum]) {
                        if (!in_array($colNum, $lockedWidthColumns)) {
                            $lockedWidthColumns[] = $colNum;
                        }
                        $numUnlockedColumns = $numCols - count($lockedWidthColumns);
                        $colWidths[$colNum] = $minWidths[$colNum];

                        if ($numUnlockedColumns < 1) {
                            continue;
                        }

                        $splitDifference = ($minWidths[$colNum] - $colWidths[$colNum]) / $numUnlockedColumns;
                        $changesMade = true;
                        for ($innerColNum = 0; $innerColNum < $numCols; $innerColNum++) {
                            if (!in_array($innerColNum, $lockedWidthColumns)) {
                                $colWidths[$innerColNum] -= $splitDifference;
                            }
                        }
                    }
                }
            }
        }


        $verticalLineYs = [];

        $xOffset = $extraWidthPerCell;
        $xStart = $x + ($this->outerBoxLines ? $this->outerBoxLineWidth : 0) + $this->cellHorizontalPadding;

        $yOffset = ($this->horizontalGridLines ? $this->gridLineWidth : 0) + ($this->cellVerticalPadding * 2);
        $yCurrent = $yStart = $y + ($this->outerBoxLines ? $this->outerBoxLineWidth : 0) + $this->cellVerticalPadding;

        $drawHeader = $this->hasHeader;
        for ($rowNum = 0, $rowCount = count($this->content); $rowNum < $rowCount; $rowNum++) {
            $row = $this->content[$rowNum];

            if ($drawHeader && $rowNum != 0) {
                $row = $this->content[$this->repeatHeaderRowNum];
            }

            $colNum = 0;
            $spanCounts = [];
            $maxHeight = 0;
            foreach ($row as $cell) {
                $cellWidth = $colWidths[$colNum];

                if ($cell == self::SPANNED_COLUMN) {
                    for ($contentColumn = $colNum; $contentColumn > -1; $contentColumn--) {
                        if ($row[$contentColumn] !== self::SPANNED_COLUMN) {
                            $spanCounts[$contentColumn]++;
                            break;
                        }
                    }
                    $spanCounts[$colNum] = 0;
                } else {
                    $spanCounts[$colNum] = 1;
                }
                $colNum++;

                $height = $page->getTextBlockHeight(
                    $cell,
                    $cellWidth,
                    (isset($this->textFonts[$rowNum][$colNum]) ? $this->textFonts[$rowNum][$colNum] : $textFont)
                );

                $maxHeight = max($height, $maxHeight);
            }

            // If printing the current row would go past the bottom of the content area, take a new page
            if ($rowNum < $rowCount && $yCurrent + $maxHeight + $yOffset >= $page->getHeight() - $this->marginBottom) {
                $this->endPage($page, $x, $width, $y, $yCurrent, $xStart, $xOffset, $colWidths, $verticalLineYs);

                $pages[] = $newPage = new Page($page->getWidth(), $page->getHeight());
                $newPage->setMargins($page->getMargins());
                $page = $newPage;

                $y = $this->marginTop;
                $yCurrent = $yStart = $y + ($this->outerBoxLines ? $this->outerBoxLineWidth : 0) + $this->cellVerticalPadding;
                $verticalLineYs = [];

                $drawHeader = true;
                $rowNum -= 2;
                continue;
            }


            $xCurrent = $xStart;
            $colNum = 0;
            $maxHeight = 0;

            foreach ($row as $cell) {
                $cellWidth = $colWidths[$colNum];

                if (!$spanCounts[$colNum]) {
                    if (!$drawHeader) {
                        $verticalLineYs[$colNum][] = $yCurrent + $this->cellVerticalPadding;
                    }
                    $verticalLineYs[$colNum][] = $yCurrent + $this->cellVerticalPadding + $maxHeight + $yOffset;
                    $colNum++;
                    continue;
                } else {
                    for ($i = 1; $i < $spanCounts[$colNum]; $i++) {
                        $cellWidth += $colWidths[$colNum + $i] + $xOffset;
                    }
                }

                if ($drawHeader) {
                    switch ($this->headerAlignments[$colNum]) {
                        case "right":
                            $alignment = Page::ALIGN_RIGHT;
                            break;
                        case "justify":
                            $alignment = Page::ALIGN_JUSTIFY;
                            break;
                        case "center":
                        case "centre":
                        case "middle":
                            $alignment = Page::ALIGN_CENTER;
                            break;
                        default:
                            $alignment = Page::ALIGN_LEFT;
                    }

                    $verticalLineYs[$colNum][] = $y;

                    $page->drawTextBlockWithStyle(
                        $cell,
                        $xCurrent,
                        $yCurrent,
                        $cellWidth,
                        $height,
                        $alignment,
                        Page::TOP,
                        $headerFont,
                        $this->headerFontSize,
                        $this->headerFontColour,
                        null,
                        null,
                        true,
                        true,
                        "UTF-8"
                    );
                } else {
                    if (!isset($verticalLineYs[$colNum])) {
                        $verticalLineYs[$colNum][] = $y;
                    }

                    switch ($this->columnAlignments[$colNum]) {
                        case "right":
                            $alignment = Page::ALIGN_RIGHT;
                            break;
                        case "justify":
                            $alignment = Page::ALIGN_JUSTIFY;
                            break;
                        case "center":
                            $alignment = Page::ALIGN_CENTER;
                            break;
                        default:
                            $alignment = Page::ALIGN_LEFT;
                    }

                    $page->drawTextBlockWithStyle(
                        $cell,
                        $xCurrent,
                        $yCurrent,
                        $cellWidth,
                        $height,
                        $alignment,
                        Page::TOP,
                        (isset($this->textFonts[$rowNum][$colNum]) ? $this->textFonts[$rowNum][$colNum] : $textFont),
                        (isset($this->textFontSizes[$rowNum][$colNum]) ? $this->textFontSizes[$rowNum][$colNum] : $this->textFontSize),
                        (isset($this->textFontColours[$rowNum][$colNum]) ? $this->textFontColours[$rowNum][$colNum] : $this->textFontColour),
                        null,
                        null,
                        true,
                        true,
                        "UTF-8"
                    );
                }

                $maxHeight = max($maxHeight, $height);
                $xCurrent += $cellWidth + $xOffset;

                $colNum++;
            }

            if (($this->horizontalGridLines xor !empty($this->overrideHorizontalGridLine[$rowNum])) && !$drawHeader && $rowNum != 0) {
                $padding = $this->cellVerticalPadding;
                if (isset($this->overrideVerticalPadding[$rowNum]) && $this->overrideVerticalPadding[$rowNum]) {
                    $padding = $this->overrideVerticalPadding[$rowNum];
                }
                $page->drawLineWithStyle($x, $yCurrent + $padding, $width + $x, $yCurrent + $padding, $this->gridLineColour, $this->gridLineWidth, true);
            }

            $yCurrent += $yOffset + $maxHeight;

            if ($rowNum < $rowCount - 1 && $yCurrent >= $page->getHeight() - $this->marginBottom) {
                $this->endPage($page, $x, $width, $y, $yCurrent, $xStart, $xOffset, $colWidths, $verticalLineYs);

                $pages[] = $newPage = new Page($page->getWidth(false), $page->getHeight());
                $newPage->setMargins($page->getMargins());
                $page = $newPage;

                $y = $this->marginTop;
                $yCurrent = $yStart = $y + ($this->outerBoxLines ? $this->outerBoxLineWidth : 0) + $this->cellVerticalPadding;
                $verticalLineYs = [];

                $drawHeader = true;
                $rowNum--;
            } else {
                $drawHeader = false;
            }
        }

        $this->endPage($page, $x, $width, $y, $yCurrent, $xStart, $xOffset, $colWidths, $verticalLineYs);

        return $pages;
    }

    private function endPage(Page $page, $left, $width, $top, $bottom, $firstColLeft, $xOffset, $colWidths, $verticalLineYs)
    {
        $numCols = count($colWidths);

        foreach ($verticalLineYs as &$array) {
            $array[] = $bottom + max($this->cellVerticalPadding, 2) - ($this->outerBoxLines ? $this->outerBoxLineWidth : 0);
        }

        if ($this->verticalGridLines) {
            $xCurrent = $firstColLeft + $this->cellHorizontalPadding + $colWidths[0];

            for ($colNum = 1; $colNum < $numCols; $colNum++) {
                for ($i = 0, $count = count($verticalLineYs[$colNum]); $i < $count; $i += 2) {
                    $yCoord1 = $verticalLineYs[$colNum][$i];
                    $yCoord2 = $verticalLineYs[$colNum][$i + 1];
                    if ($yCoord2 - $yCoord1 <= $this->outerBoxLineWidth || $yCoord2 - $yCoord1 <= $this->gridLineWidth) {
                        continue;
                    }

                    $page->drawLineWithStyle(
                        $xCurrent,
                        $yCoord1,
                        $xCurrent,
                        $yCoord2,
                        $this->gridLineColour,
                        $this->gridLineWidth,
                        true
                    );
                }

                $xCurrent += $xOffset + $colWidths[$colNum];
            }
        }

        if ($this->outerBoxLines) {
            $page->drawRectangleWithStyle(
                $left,
                $top,
                $width + $left,
                $bottom + $this->outerBoxLineWidth,
                null,
                $this->outerBoxLineColour,
                $this->outerBoxLineWidth,
                $this->outerBoxRadius,
                true
            );

            $this->renderedLastPageHeight = $bottom + ($this->outerBoxLineWidth * 2) - $top;
            $this->renderedHeight += $this->renderedLastPageHeight;
        } else {
            $this->renderedLastPageHeight = $bottom - $top;
            $this->renderedHeight += $this->renderedLastPageHeight;
        }
    }

    public function removeColumns()
    {
        $columnIndexes = func_get_args();
        $numCols = count($this->content[0]);

        if (!count($columnIndexes)) {
            return;
        }

        foreach ($columnIndexes as $index) {
            unset($this->columnWidths[$index]);
            for ($i = $index + 1; $i < $numCols; $i++) {
                $this->columnWidths[$i - 1] = $this->columnWidths[$i];
                unset($this->columnWidths[$i]);
            }
            $numCols--;
        }

        for ($rowNum = 0, $numRows = count($this->content); $rowNum < $numRows; $rowNum++) {
            $numCols = count($this->content[$rowNum]);
            foreach ($columnIndexes as $index) {
                unset($this->content[$rowNum][$index]);
                unset($this->textFonts[$rowNum][$index]);
                unset($this->textFontSizes[$rowNum][$index]);
                unset($this->textFontColours[$rowNum][$index]);

                for ($i = $index + 1; $i < $numCols; $i++) {
                    $this->content[$rowNum][$i - 1] = $this->content[$rowNum][$i];
                    unset($this->content[$rowNum][$i]);
                    $this->textFonts[$rowNum][$i - 1] = $this->textFonts[$rowNum][$i];
                    unset($this->textFonts[$rowNum][$i]);
                    $this->textFontSizes[$rowNum][$i - 1] = $this->textFontSizes[$rowNum][$i];
                    unset($this->textFontSizes[$rowNum][$i]);
                    $this->textFontColours[$rowNum][$i - 1] = $this->textFontColours[$rowNum][$i];
                    unset($this->textFontColours[$rowNum][$i]);
                }
                $numCols--;
            }
        }
    }

    public function isRenderedOnMultiplePages()
    {
        return ($this->renderedLastPageHeight < $this->renderedHeight);
    }

    public function getEndOfTableY()
    {
        if ($this->isRenderedOnMultiplePages()) {
            return $this->marginTop + $this->renderedLastPageHeight;
        } else {
            return $this->y + $this->renderedHeight;
        }
    }
}
