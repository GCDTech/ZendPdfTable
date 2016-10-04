<?php

namespace ZendPdfTable;

class BarcodeGenerationException extends \Exception
{
    public function __construct( $message = null, $barcodeText = null, $barcodeType = null )
    {
        if( $barcodeType )
        {
            $barcodeType .= " ";
        }

        $this->message = "There was a problem generating a {$barcodeType}barcode for text $barcodeText.";

        if( $message != null )
        {
            $this->message .= " $message";
        }
    }
}
