<?php
namespace ZboziCZ\Utils;

class XmlHelper {
    public function __construct( private \XMLWriter $x ) {}

    public function element_text( string $name, string $value ): void {
        $this->x->startElement( $name );
        // Avoid control chars
        $this->x->text( preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value ) );
        $this->x->endElement();
    }
}
