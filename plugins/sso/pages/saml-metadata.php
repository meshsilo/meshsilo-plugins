<?php
/**
 * SAML Service Provider Metadata
 *
 * Generates SP metadata XML for IdP configuration
 */

header('Content-Type: application/xml');
header('Content-Disposition: attachment; filename="silo-sp-metadata.xml"');

echo generateSPMetadata();
