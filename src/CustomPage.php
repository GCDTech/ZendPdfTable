<?php

namespace ZendPdfTable;

use ZendPdf\Color\Cmyk;
use ZendPdf\Color\GrayScale;
use ZendPdf\Color\Html;
use ZendPdf\Color\Rgb;
use ZendPdf\Exception\InvalidArgumentException;
use ZendPdf\Exception\RuntimeException;
use ZendPdf\Exception\UnrecognizedFontException;
use ZendPdf\Font;
use ZendPdf\InternalType\NumericObject;
use ZendPdf\InternalType\StringObject;
use ZendPdf\Page;
use ZendPdf\Resource\Font\AbstractFont;
use ZendPdf\Style;

class CustomPage extends Page
{
    /**
     * Constants for PDF Tables
     */
    const TOP = 0;
    const RIGHT = 1;
    const BOTTOM = 2;
    const LEFT = 3;
    const CENTER = 4;    //horizontal center
    const MIDDLE = 5; //vertical center

    /**
     * Left align text block.
     */
    const ALIGN_LEFT = 'left';

    /**
     * Right align text block.
     */
    const ALIGN_RIGHT = 'right';

    /**
     * Center text block.
     */
    const ALIGN_CENTER = 'center';

    /**
     * Justify text in block.
     */
    const ALIGN_JUSTIFY = 'justify';

    public $defaultInContentArea = false;

    /**
     * x position when the cursor was set. Used after line wrapping.
     */
    protected $cursorOriginalX = 0;

    /**
     * Current x position of cursor.
     */
    protected $cursorX = 0;

    /**
     * Current y position of cursor.
     */
    protected $cursorY = 0;

    private $margin;

    public function getTextWidths($text, $fontPathOrName = null, $size = null)
    {
        $font = null;
        if ($fontPathOrName) {
            if (is_object($fontPathOrName)) {
                $font = $fontPathOrName;
            } elseif (file_exists($fontPathOrName)) {
                $font = Font::fontWithPath($fontPathOrName);
            } else {
                $font = Font::fontWithName($fontPathOrName);
            }
        }

        $text = self::removeHtmlChars(str_replace("\r", "", $text), false);

        $unwrappedText = explode("\n", $text);
        $normalWidth = 0;
        foreach ($unwrappedText as $line) {
            $normalWidth = max($this->widthForString(trim($line), "ISO-8859-1", $font, $size), $normalWidth);
        }
        $normalHeight = $this->getFontHeight() * count($unwrappedText);

        $wrappedText = explode(" ", str_replace("\n", " ", $text));
        $resizedWidth = 0;
        foreach ($wrappedText as $line) {
            $line = str_replace("&nbsp;", " ", $line);
            $resizedWidth = max($this->widthForString(trim($line), "ISO-8859-1", $font, $size), $resizedWidth);
        }
        $resizedHeight = $this->getFontHeight() * count($wrappedText);

        return [
            'normal_width' => $normalWidth,
            'normal_height' => $normalHeight,
            'resized_width' => $resizedWidth,
            'resized_height' => $resizedHeight
        ];
    }

    /**
     * Get Font Height
     *
     * @return int
     */
    public function getFontHeight()
    {
        $lineHeight = $this->getFont()->getLineHeight();
        $lineGap = $this->getFont()->getLineGap();
        $unitsPerEm = $this->getFont()->getUnitsPerEm();
        $size = $this->getFontSize();
        return ($lineHeight - $lineGap) / $unitsPerEm * $size;
    }

    public static function removeHtmlChars($text, $includeSpaces = true)
    {
        $replacements = ["&amp;" => "&"];

        if ($includeSpaces) {
            $replacements["&nbsp;"] = " ";
        }

        return str_replace(array_keys($replacements), $replacements, $text);
    }

    /**
     * Calculate the width for the given string.
     *
     * @param string $string
     * @param string $charset charset of the string
     * @param AbstractFont $font
     * @param float $fontSize
     * @return int width of string
     */
    public function widthForString($string, $charset = 'ISO-8859-1', $font = null, $fontSize = null)
    {
        if (!$font) {
            $font = $this->_font;
        }
        if (!$fontSize) {
            $fontSize = $this->_fontSize;
        }

        $drawingString = iconv($charset, 'UTF-16BE//IGNORE', $string);
        $characters = [];
        for ($i = 0; $i < strlen($drawingString); $i++) {
            $characters[] = (ord($drawingString[$i++]) << 8) | ord($drawingString[$i]);
        }

        $glyphs = $font->glyphNumbersForCharacters($characters);
        $widths = $font->widthsForGlyphs($glyphs);

        $stringWidth = (array_sum($widths) / $font->getUnitsPerEm()) * $fontSize;
        return $stringWidth;
    }

    public function getTextBlockHeight($text, $width, $fontPathOrName, $size = 11, $lineHeight = null, $extraSpacing = 1)
    {
        $tempFont = $this->_font;
        $tempFontSize = $this->_fontSize;

        if (is_object($fontPathOrName)) {
            $this->_font = $fontPathOrName;
        } elseif (file_exists($fontPathOrName)) {
            $this->_font = Font::fontWithPath($fontPathOrName);
        } else {
            $this->_font = Font::fontWithName($fontPathOrName);
        }

        $this->_fontSize = $size;

        $lines = $this->wrapText($text, $width);

        if ($lineHeight === null) {
            $lineHeight = ($size * ($this->_font->getLineHeight() / $this->_font->getUnitsPerEm())) + $extraSpacing;
        }

        $height = count($lines) * $lineHeight;

        $this->_font = $tempFont;
        $this->_fontSize = $tempFontSize;

        return $height;
    }

    /**
     * Helper method to wrap text to lines. The wrapping is done at newlines and at spaces if the text gets longer than $width.
     *
     * @param string $text the text to wrap
     * @param int $width
     * @param int $initialLineLength x offset for start position in first line
     * @return array array with lines as array('words' => array(...), 'word_lengths' => array(...), 'total_length' => <int>)
     */
    protected function wrapText($text, $width, $initialLineLength = 0)
    {
        $lines = [];
        $lineInit = [
            'words' => [],
            'word_lengths' => [],
            'total_length' => 0
        ];
        $line = $lineInit;
        $line['total_length'] = $initialLineLength;

        $spaceLength = $this->widthForString(" ");

        $subLines = explode("\n", str_replace("\r", "", self::removeHtmlChars($text, false)));
        foreach ($subLines as $text) {
            $text = array_filter(explode(" ", $text), "is_empty_string");
            foreach ($text as $word) {
                $word = str_replace("&nbsp;", " ", $word);
                $wordLength = $this->widthForString($word);
                if ($wordLength > $width) {
                    if ($line['words']) {
                        $lines[] = $line;
                    }
                    $lines[] = [
                        'words' => [$word],
                        'word_lengths' => [$wordLength],
                        'total_length' => $wordLength
                    ];
                    $line = $lineInit;
                    continue;
                }
                if ($line['total_length'] + $wordLength > $width) {
                    $line['total_length'] -= $spaceLength;
                    $lines[] = $line;
                    $line = $lineInit;
                }
                $line['words'][] = $word;
                $line['word_lengths'][] = $wordLength;
                $line['total_length'] += $wordLength + $spaceLength;
            }
            if (count($line['words'])) {
                $line['total_length'] -= $spaceLength;
                $lines[] = $line;
            } else {
                $lines[] = ['words' => [], 'word_lengths' => [], 'total_length' => 0];
            }
            $line = $lineInit;
        }

        return $lines;
    }

    /**
     * Draw a text in a block with a fixed width and an optional fixed height. The text can be left or right
     * aligned, centered of justified. If height is given, but the text would be longer an exception is thrown.
     *
     * @param string $text
     * @param int $xPosition x position (or width if width is passed as null, in which case the current cursor position will be used for coordinates)
     * @param int|null $yPosition y position
     * @param int|null $width block width
     * @param int|null $height optional variable to store the height of the rendered block
     * @param string $align one of: self::ALIGN_LEFT, self::ALIGN_RIGHT, self::ALIGN_CENTER, self::ALIGN_JUSTIFY
     * @param mixed $yStart Sets whether the block top or bottom should start at the specified y co-ordinate. self::TOP or self::BOTTOM.
     * @param mixed $fontPathOrName Can be a Font object, a font name, or a font file path
     * @param float|null $size Font size
     * @param mixed $color Font colour. See self::parseColor() for allowed types
     * @param int|null $angle
     * @param int|null $lineHeight Set from font size if not specified
     * @param bool $inContentArea
     * @param bool $yStartsAboveLine
     * @param string $charEncoding (optional) Character encoding of source text. Defaults to current locale.
     *
     * @throws RuntimeException
     */
    public function drawTextBlockWithStyle(
        $text,
        $xPosition,
        $yPosition = null,
        $width = null,
        &$height = null,
        $align = self::ALIGN_LEFT,
        $yStart = self::TOP,
        $fontPathOrName = null,
        $size = null,
        $color = null,
        $angle = null,
        $lineHeight = null,
        $inContentArea = null,
        $yStartsAboveLine = false,
        $charEncoding = ""
    ) {
        if ($inContentArea === null) {
            $inContentArea = $this->defaultInContentArea;
        }

        if ($width === null) {
            $width = $xPosition;
            $this->textCursorNewline();
            $xPosition = $this->cursorX;
            $yPosition = $this->cursorY;
        }

        $this->saveGS();

        if ($fontPathOrName && $size && $color) {
            $style = new Style();
            if (is_object($fontPathOrName)) {
                $font = $fontPathOrName;
            } elseif (file_exists($fontPathOrName)) {
                $font = Font::fontWithPath($fontPathOrName);
            } else {
                $font = Font::fontWithName($fontPathOrName);
            }

            $style->setFont($font, $size);

            $style->setFillColor(self::parseColor($color));

            $this->setStyle($style);
        } elseif ($fontPathOrName || $size || $color) {
            throw new InvalidArgumentException('If you pass any of the font path, size or color to drawText you must provide all 3');
        }

        if ($angle) {
            $radians = deg2rad($angle);
            $this->rotate($xPosition, $yPosition, $radians);
        }

        if ($this->_font === null) {
            throw new UnrecognizedFontException('Font has not been set');
        }

        $lines = $this->wrapText($text, $width);

        if ($lineHeight === null) {
            $lineHeight = $this->getLineHeight();
        }

        $height = count($lines) * $lineHeight;

        if ($yStart == self::BOTTOM) {
            if ($inContentArea) {
                $yPosition -= $height;
            } else {
                $yPosition += $height;
            }
        }

        if ($yStartsAboveLine && $inContentArea) {
            $yPosition += $lineHeight;
        }

        foreach ($lines as $lineNum => $line) {
            switch ($align) {
                case self::ALIGN_JUSTIFY:
                    if (count($line["words"]) < 2 || $lineNum == count($lines) - 1) {
                        $this->drawText(implode(" ", $line["words"]), $xPosition, $yPosition, $charEncoding, $inContentArea);
                        break;
                    }
                    $spaceWidth = ($width - array_sum($line["word_lengths"])) / (count($line["words"]) - 1);
                    $pos = $xPosition;
                    foreach ($line["words"] as $wordNum => $word) {
                        $this->drawText($word, $pos, $yPosition, $charEncoding, $inContentArea);
                        $pos += $line["word_lengths"][$wordNum] + $spaceWidth;
                    }
                    break;

                case self::ALIGN_CENTER:
                    $this->drawText(implode(" ", $line["words"]), $xPosition + ($width - $line["total_length"]) / 2, $yPosition, $charEncoding, $inContentArea);
                    break;

                case self::ALIGN_RIGHT:
                    $this->drawText(implode(" ", $line["words"]), $xPosition + $width - $line["total_length"], $yPosition, $charEncoding, $inContentArea);
                    break;

                case self::ALIGN_LEFT:
                default:
                    $this->drawText(implode(" ", $line["words"]), $xPosition, $yPosition, $charEncoding, $inContentArea);
                    break;
            }
            if ($inContentArea) {
                $yPosition += $lineHeight;
            } else {
                $yPosition -= $lineHeight;
            }
        }

        $this->restoreGS();
    }

    /**
     * Draw a line of text at the specified position.
     *
     * @param string $text
     * @param float $xPosition
     * @param float $yPosition
     * @param string $charEncoding (optional) Character encoding of source text.
     *   Defaults to current locale.
     * @param null $inContentArea
     * @return static
     */
    public function drawText($text, $xPosition, $yPosition, $charEncoding = '', $inContentArea = null)
    {
        if ($inContentArea === null) {
            $inContentArea = $this->defaultInContentArea;
        }

        if ($inContentArea) {
            $yPosition = $this->getHeight() - $yPosition - $this->getMargin(self::TOP);
            $xPosition = $xPosition + $this->getMargin(self::LEFT);
        }

        if ($this->_font === null) {
            throw new UnrecognizedFontException('Font has not been set');
        }

        $this->_addProcSet('Text');

        $textObj = new StringObject($this->_font->encodeString($text, $charEncoding));
        $xObj = new NumericObject($xPosition);
        $yObj = new NumericObject($yPosition);

        $this->_contents .= "BT\n"
            . $xObj->toString() . ' ' . $yObj->toString() . " Td\n"
            . $textObj->toString() . " Tj\n"
            . "ET\n";

        return $this;
    }


    /**
     * This method will take a parameter matching any possible Zend_Pdf_Color type and create a new object matching the colour.
     *
     * @param mixed $color A Zend_Pdf_Color instance, a string representing an HTML colour code, an int or float representing a grayscale level,
     *                        an array of 3 floats representing an RGB colour, or an array of 4 floats representing a CMYK colour.
     * @return Html|GrayScale|Rgb|Cmyk
     */
    public static function parseColor($color)
    {
        if (!is_object($color)) {
            if (is_string($color)) {
                $color = new Html($color);
            } elseif (is_float($color) || is_int($color)) {
                $color = new GrayScale($color);
            } elseif (is_array($color)) {
                if (count($color) == 3) {
                    $color = new Rgb($color[0], $color[1], $color[2]);
                } else {
                    $color = new Cmyk($color[0], $color[1], $color[2], $color[3]);
                }
            }
        }
        return $color;
    }

    /**
     * Start a newline. The x position is reset and line height is added to the y position
     *
     * @return self fluid interface
     */
    public function textCursorNewline()
    {
        $this->setTextCursor($this->cursorOriginalX);
        $this->textCursorMove(null, -$this->getLineHeight());
        return $this;
    }

    /**
     * Set position of text cursor.
     *
     * @param int $xPosition x position
     * @param int $yPosition y position
     * @return self fluid interface
     */
    public function setTextCursor($xPosition = null, $yPosition = null)
    {
        if ($xPosition !== null) {
            $this->cursorOriginalX = $xPosition;
            $this->cursorX = $xPosition;
        }
        if ($yPosition !== null) {
            $this->cursorY = $yPosition;
        }

        return $this;
    }

    /**
     * Move text cursor in relation to current position
     *
     * @param float $xOffset x offset
     * @param float $yOffset y offset
     * @return self fluid interface
     */
    public function textCursorMove($xOffset = null, $yOffset = null)
    {
        if ($xOffset !== null) {
            $this->cursorX += $xOffset;
        }
        if ($yOffset !== null) {
            $this->cursorY += $yOffset;
        }

        return $this;
    }

    /**
     * Get height of one or more line(s) in with current font and font size.
     *
     * @param int $lines number of lines
     * @param int $extraSpacing spaceing between lines
     * @return int line height
     */
    public function getLineHeight($lines = 1, $extraSpacing = 1)
    {
        return $lines * (($this->_fontSize * ($this->_font->getLineHeight() / $this->_font->getUnitsPerEm())) + $extraSpacing);
    }

    /**
     * Draw a line from x1,y1 to x2,y2.
     *
     * @param float $xCoord1
     * @param float $yCoord1
     * @param float $xCoord2
     * @param float $yCoord2
     * @param mixed $strokeColour
     * @param float $strokeWidth
     * @param bool $inContentArea
     * @return self
     */
    public function drawLineWithStyle($xCoord1, $yCoord1, $xCoord2, $yCoord2, $strokeColour = null, $strokeWidth = null, $inContentArea = null)
    {
        if ($inContentArea === null) {
            $inContentArea = $this->defaultInContentArea;
        }

        if ($strokeColour !== null) {
            $this->saveGS();

            $this->setLineColor(self::parseColor($strokeColour))
                ->setLineWidth($strokeWidth);
        }

        if ($inContentArea) {
            $yCoord1 = $this->getHeight() - $yCoord1 - $this->getMargin(self::TOP);
            $yCoord2 = $this->getHeight() - $yCoord2 - $this->getMargin(self::TOP);
            $xCoord1 = $xCoord1 + $this->getMargin(self::LEFT);
            $xCoord2 = $xCoord2 + $this->getMargin(self::LEFT);
        }

        $this->_addProcSet('PDF');

        $x1Obj = new NumericObject($xCoord1);
        $y1Obj = new NumericObject($yCoord1);
        $x2Obj = new NumericObject($xCoord2);
        $y2Obj = new NumericObject($yCoord2);

        $this->_contents .= $x1Obj->toString() . ' ' . $y1Obj->toString() . " m\n"
            . $x2Obj->toString() . ' ' . $y2Obj->toString() . " l\n S\n";

        if ($strokeColour !== null) {
            $this->restoreGS();
        }

        return $this;
    }

    /**
     * Get a Page margin
     *
     * @param Zend_Pdf ::Position $position
     * @return int margin
     */
    public function getMargin($position)
    {
        return $this->margin[$position];
    }

    /**
     * Get Page Margins
     *
     * @return array(TOP,RIGHT,BOTTOM,LEFT)
     */
    public function getMargins()
    {
        return $this->margin;
    }

    /**
     * Set page margins
     *
     * @param array (TOP,RIGHT,BOTTOM,LEFT)
     */
    public function setMargins($margin = [])
    {
        $this->margin = $margin;
    }

    /**
     * Get Page Width
     *
     * @param bool $inContentArea
     * @return int
     */
    public function getWidth($inContentArea = false)
    {
        $width = parent::getWidth();

        if ($inContentArea) {
            $width -= $this->margin[self::LEFT];
            $width -= $this->margin[self::RIGHT];
        }

        return $width;
    }

    public function drawRectangleWithStyle($xCoord1, $yCoord1, $xCoord2, $yCoord2, $fillColour = null, $strokeColour = null, $strokeWidth = 1, $cornerRadius = 0, $inContentArea = null)
    {
        if ($inContentArea === null) {
            $inContentArea = $this->defaultInContentArea;
        }

        $this->saveGS();

        if ($fillColour !== null) {
            $fillType = Page::SHAPE_DRAW_FILL;

            $this->setFillColor(self::parseColor($fillColour));
        }
        if ($strokeColour !== null) {
            $fillType = Page::SHAPE_DRAW_STROKE;
            $this->setLineColor(self::parseColor($strokeColour))
                ->setLineWidth($strokeWidth);
        }
        if ($fillColour !== null && $strokeColour != null) {
            $fillType = Page::SHAPE_DRAW_FILL_AND_STROKE;
        }

        if (!isset($fillType)) {
            throw new InvalidArgumentException("At least one of fillColour or strokeColour must be defined must be defined");
        }

        if ((is_int($cornerRadius) && $cornerRadius > 0) ||
            (is_array($cornerRadius) && count($cornerRadius) == 4)
        ) {
            $this->drawRoundedRectangle($xCoord1, $yCoord1, $xCoord2, $yCoord2, $cornerRadius, $fillType, $inContentArea);
        } else {
            $this->drawRectangle($xCoord1, $yCoord1, $xCoord2, $yCoord2, $fillType, $inContentArea);
        }

        $this->restoreGS();

        return $this;
    }

    /**
     * Draw a rounded rectangle.
     *
     * Fill types:
     * Zend_Pdf_Page::SHAPE_DRAW_FILL_AND_STROKE - fill rectangle and stroke (default)
     * Zend_Pdf_Page::SHAPE_DRAW_STROKE      - stroke rectangle
     * Zend_Pdf_Page::SHAPE_DRAW_FILL        - fill rectangle
     *
     * radius is an integer representing radius of the four corners, or an array
     * of four integers representing the radius starting at top left, going
     * clockwise
     *
     * @param float $xCoord1
     * @param float $yCoord1
     * @param float $xCoord2
     * @param float $yCoord2
     * @param integer|array $radius
     * @param int $fillType
     * @param null $inContentArea
     * @return static
     */
    public function drawRoundedRectangle(
        $xCoord1,
        $yCoord1,
        $xCoord2,
        $yCoord2,
        $radius,
        $fillType = self::SHAPE_DRAW_FILL_AND_STROKE,
        $inContentArea = null
    ) {
        if ($inContentArea === null) {
            $inContentArea = $this->defaultInContentArea;
        }

        if ($inContentArea) {
            $width = abs($xCoord2 - $xCoord1);
            $height = abs($yCoord2 - $yCoord1);

            $yCoord1 = $this->getHeight() - $yCoord1 - $this->getMargin(self::TOP) - $height;
            $xCoord1 = $xCoord1 + $this->getMargin(self::LEFT);

            $yCoord2 = $yCoord1 + $height;
            $xCoord2 = $xCoord1 + $width;
        }

        $this->_addProcSet('PDF');

        if (!is_array($radius)) {
            $radius = [$radius, $radius, $radius, $radius];
        } else {
            for ($i = 0; $i < 4; $i++) {
                if (!isset($radius[$i])) {
                    $radius[$i] = 0;
                }
            }
        }

        $topLeftX = $xCoord1;
        $topLeftY = $yCoord2;
        $topRightX = $xCoord2;
        $topRightY = $yCoord2;
        $bottomRightX = $xCoord2;
        $bottomRightY = $yCoord1;
        $bottomLeftX = $xCoord1;
        $bottomLeftY = $yCoord1;

        //draw top side
        $x1Obj = new NumericObject($topLeftX + $radius[0]);
        $y1Obj = new NumericObject($topLeftY);
        $this->_contents .= $x1Obj->toString() . ' ' . $y1Obj->toString() . " m\n";
        $x1Obj = new NumericObject($topRightX - $radius[1]);
        $y1Obj = new NumericObject($topRightY);
        $this->_contents .= $x1Obj->toString() . ' ' . $y1Obj->toString() . " l\n";

        //draw top right corner if needed
        if ($radius[1] != 0) {
            $x1Obj = new NumericObject($topRightX);
            $y1Obj = new NumericObject($topRightY);
            $x2Obj = new NumericObject($topRightX);
            $y2Obj = new NumericObject($topRightY);
            $x3Obj = new NumericObject($topRightX);
            $y3Obj = new NumericObject($topRightY - $radius[1]);
            $this->_contents .= $x1Obj->toString() . ' ' . $y1Obj->toString() . ' '
                . $x2Obj->toString() . ' ' . $y2Obj->toString() . ' '
                . $x3Obj->toString() . ' ' . $y3Obj->toString() . ' '
                . " c\n";
        }

        //draw right side
        $x1Obj = new NumericObject($bottomRightX);
        $y1Obj = new NumericObject($bottomRightY + $radius[2]);
        $this->_contents .= $x1Obj->toString() . ' ' . $y1Obj->toString() . " l\n";

        //draw bottom right corner if needed
        if ($radius[2] != 0) {
            $x1Obj = new NumericObject($bottomRightX);
            $y1Obj = new NumericObject($bottomRightY);
            $x2Obj = new NumericObject($bottomRightX);
            $y2Obj = new NumericObject($bottomRightY);
            $x3Obj = new NumericObject($bottomRightX - $radius[2]);
            $y3Obj = new NumericObject($bottomRightY);
            $this->_contents .= $x1Obj->toString() . ' ' . $y1Obj->toString() . ' '
                . $x2Obj->toString() . ' ' . $y2Obj->toString() . ' '
                . $x3Obj->toString() . ' ' . $y3Obj->toString() . ' '
                . " c\n";
        }

        //draw bottom side
        $x1Obj = new NumericObject($bottomLeftX + $radius[3]);
        $y1Obj = new NumericObject($bottomLeftY);
        $this->_contents .= $x1Obj->toString() . ' ' . $y1Obj->toString() . " l\n";

        //draw bottom left corner if needed
        if ($radius[3] != 0) {
            $x1Obj = new NumericObject($bottomLeftX);
            $y1Obj = new NumericObject($bottomLeftY);
            $x2Obj = new NumericObject($bottomLeftX);
            $y2Obj = new NumericObject($bottomLeftY);
            $x3Obj = new NumericObject($bottomLeftX);
            $y3Obj = new NumericObject($bottomLeftY + $radius[3]);
            $this->_contents .= $x1Obj->toString() . ' ' . $y1Obj->toString() . ' '
                . $x2Obj->toString() . ' ' . $y2Obj->toString() . ' '
                . $x3Obj->toString() . ' ' . $y3Obj->toString() . ' '
                . " c\n";
        }

        //draw left side
        $x1Obj = new NumericObject($topLeftX);
        $y1Obj = new NumericObject($topLeftY - $radius[0]);
        $this->_contents .= $x1Obj->toString() . ' ' . $y1Obj->toString() . " l\n";

        //draw top left corner if needed
        if ($radius[0] != 0) {
            $x1Obj = new NumericObject($topLeftX);
            $y1Obj = new NumericObject($topLeftY);
            $x2Obj = new NumericObject($topLeftX);
            $y2Obj = new NumericObject($topLeftY);
            $x3Obj = new NumericObject($topLeftX + $radius[0]);
            $y3Obj = new NumericObject($topLeftY);
            $this->_contents .= $x1Obj->toString() . ' ' . $y1Obj->toString() . ' '
                . $x2Obj->toString() . ' ' . $y2Obj->toString() . ' '
                . $x3Obj->toString() . ' ' . $y3Obj->toString() . ' '
                . " c\n";
        }

        switch ($fillType) {
            case self::SHAPE_DRAW_FILL_AND_STROKE:
                $this->_contents .= " B*\n";
                break;
            case self::SHAPE_DRAW_FILL:
                $this->_contents .= " f*\n";
                break;
            case self::SHAPE_DRAW_STROKE:
                $this->_contents .= " S\n";
                break;
        }

        return $this;
    }

    /**
     * Draw a rectangle.
     *
     * Fill types:
     * Zend_Pdf_Page::SHAPE_DRAW_FILL_AND_STROKE - fill rectangle and stroke (default)
     * Zend_Pdf_Page::SHAPE_DRAW_STROKE      - stroke rectangle
     * Zend_Pdf_Page::SHAPE_DRAW_FILL        - fill rectangle
     *
     * @param float $xCoord1
     * @param float $yCoord1
     * @param float $xCoord2
     * @param float $yCoord2
     * @param int $fillType
     * @param null $inContentArea
     * @return static
     */
    public function drawRectangle($xCoord1, $yCoord1, $xCoord2, $yCoord2, $fillType = self::SHAPE_DRAW_FILL_AND_STROKE, $inContentArea = null)
    {
        if ($inContentArea === null) {
            $inContentArea = $this->defaultInContentArea;
        }

        if ($xCoord1 > $xCoord2) {
            $temp = $xCoord1;
            $xCoord1 = $xCoord2;
            $xCoord2 = $temp;
        }
        if ($yCoord1 > $yCoord2) {
            $temp = $yCoord1;
            $yCoord1 = $yCoord2;
            $yCoord2 = $temp;
        }

        $width = $xCoord2 - $xCoord1;
        $height = $yCoord2 - $yCoord1;

        if ($inContentArea) {
            $yCoord1 = $this->getHeight() - $yCoord1 - $this->getMargin(self::TOP) - $height;
            $xCoord1 = $xCoord1 + $this->getMargin(self::LEFT);
        }

        $this->_addProcSet('PDF');

        $x1Obj = new NumericObject($xCoord1);
        $y1Obj = new NumericObject($yCoord1);
        $widthObj = new NumericObject($width);
        $height2Obj = new NumericObject($height);

        $this->_contents .= $x1Obj->toString() . ' ' . $y1Obj->toString() . ' '
            . $widthObj->toString() . ' ' . $height2Obj->toString() . " re\n";

        switch ($fillType) {
            case self::SHAPE_DRAW_FILL_AND_STROKE:
                $this->_contents .= " B*\n";
                break;
            case self::SHAPE_DRAW_FILL:
                $this->_contents .= " f*\n";
                break;
            case self::SHAPE_DRAW_STROKE:
                $this->_contents .= " S\n";
                break;
        }

        return $this;
    }
}
