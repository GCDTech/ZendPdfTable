<?php

namespace ZendPdfTable;

class BarcodeImage
{
    private static $code128encoding = [
        "11011001100",
        "11001101100",
        "11001100110",
        "10010011000",
        "10010001100",
        "10001001100",
        "10011001000",
        "10011000100",
        "10001100100",
        "11001001000",
        "11001000100",
        "11000100100",
        "10110011100",
        "10011011100",
        "10011001110",
        "10111001100",
        "10011101100",
        "10011100110",
        "11001110010",
        "11001011100",
        "11001001110",
        "11011100100",
        "11001110100",
        "11101101110",
        "11101001100",
        "11100101100",
        "11100100110",
        "11101100100",
        "11100110100",
        "11100110010",
        "11011011000",
        "11011000110",
        "11000110110",
        "10100011000",
        "10001011000",
        "10001000110",
        "10110001000",
        "10001101000",
        "10001100010",
        "11010001000",
        "11000101000",
        "11000100010",
        "10110111000",
        "10110001110",
        "10001101110",
        "10111011000",
        "10111000110",
        "10001110110",
        "11101110110",
        "11010001110",
        "11000101110",
        "11011101000",
        "11011100010",
        "11011101110",
        "11101011000",
        "11101000110",
        "11100010110",
        "11101101000",
        "11101100010",
        "11100011010",
        "11101111010",
        "11001000010",
        "11110001010",
        "10100110000",
        "10100001100",
        "10010110000",
        "10010000110",
        "10000101100",
        "10000100110",
        "10110010000",
        "10110000100",
        "10011010000",
        "10011000010",
        "10000110100",
        "10000110010",
        "11000010010",
        "11001010000",
        "11110111010",
        "11000010100",
        "10001111010",
        "10100111100",
        "10010111100",
        "10010011110",
        "10111100100",
        "10011110100",
        "10011110010",
        "11110100100",
        "11110010100",
        "11110010010",
        "11011011110",
        "11011110110",
        "11110110110",
        "10101111000",
        "10100011110",
        "10001011110",
        "10111101000",
        "10111100010",
        "11110101000",
        "11110100010",
        "10111011110",
        "10111101110",
        "11101011110",
        "11110101110",
        "11010000100",
        "11010010000",
        "11010011100",
        "1100011101011"
    ];

    public static function Code128($barcodeText, $code128Mode = "auto")
    {
        if ($code128Mode == "auto") {
            if (is_int($barcodeText)) {
                $currentCode128Mode = "C";
            } else {
                $currentCode128Mode = "B";
            }
        } else if ($code128Mode == "mixed") {
            if (is_int(substr($barcodeText, 0, 2))) {
                $currentCode128Mode = "C";
            } else {
                $currentCode128Mode = "B";
            }
        } else {
            $currentCode128Mode = $code128Mode;

            if ($code128Mode == "C") {
                if (!is_int($barcodeText)) {
                    throw new BarcodeGenerationException("For Code 128 Set C, the barcode must contain only integers.", $barcodeText);
                }
                if (strlen($barcodeText) % 2 === 1) {
                    throw new BarcodeGenerationException("For Code 128 Set C, the barcode length must be even.", $barcodeText);
                }
            }
        }

        $codes[] = ($currentCode128Mode == "A" ? 103 : ($currentCode128Mode == "B" ? 104 : 105));

        for ($i = 0; $i < strlen($barcodeText); $i++) {
            $asciiCode = ord($barcodeText[$i]);
            if ($currentCode128Mode == "A") {
                if ($asciiCode > 31 && $asciiCode < 96) {
                    $codes[] = $asciiCode - 32;
                } else {
                    throw new BarcodeGenerationException("For Code 128 Set A, all characters must be between ASCII code 32 and 95 (inclusive).", $barcodeText);
                }
            } else if ($currentCode128Mode == "B") {
                if ($asciiCode > 31 && $asciiCode < 128) {
                    $codes[] = $asciiCode - 32;
                } else {
                    throw new BarcodeGenerationException("For Code 128 Set B, all characters must be between ASCII code 32 and 127 (inclusive).", $barcodeText);
                }
            } else if ($currentCode128Mode == "C") {
                $codes[] = (int)substr($barcodeText, $i, 2);
                $i++;
            }

            if ($code128Mode == "mixed") {
                $charPair = substr($barcodeText, $i + 1, 2);
                if ($currentCode128Mode == "B" && ctype_digit($charPair) && strlen($charPair) === 2) {
                    $codes[] = 99;
                    $currentCode128Mode = "C";
                } else if ($currentCode128Mode == "C" && (!ctype_digit($charPair) || strlen($charPair) === 1)) {
                    $codes[] = 100;
                    $currentCode128Mode = "B";
                }
            }
        }

        $sum = $codes[0];
        for ($i = 1; $i < count($codes); $i++) {
            $sum += $i * $codes[$i];
        }
        $remainder = $sum % 103;
        $codes[] = $remainder;

        $codes[] = 106;

        $output = "";
        foreach ($codes as $code) {
            $output .= BarcodeImage::$code128encoding[$code];
        }

        return $output;
    }
}
