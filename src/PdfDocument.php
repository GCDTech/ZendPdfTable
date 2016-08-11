<?php

namespace ZendPdfTable;

class PdfDocument extends \ZendPdf\PdfDocument
{
    public function newPage($param1, $param2 = null)
    {
        if ($param2 === null) {
            return new Page($param1, $this->_objFactory);
        } else {
            return new Page($param1, $param2, $this->_objFactory);
        }
    }
}
