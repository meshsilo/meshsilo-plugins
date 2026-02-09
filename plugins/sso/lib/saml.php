<?php
/**
 * SAML 2.0 Authentication Helper
 *
 * Provides SAML SSO functionality following similar patterns to oidc.php
 * Supports IdP metadata URL or manual configuration
 */

/**
 * Check if SAML is enabled and properly configured
 */
function isSAMLEnabled() {
    return getSetting('saml_enabled', '0') === '1'
        && !empty(getSetting('saml_idp_entity_id'))
        && !empty(getSetting('saml_idp_sso_url'));
}

/**
 * Get SAML Service Provider Entity ID
 */
function getSAMLSPEntityId() {
    $configured = getSetting('saml_sp_entity_id', '');
    if (!empty($configured)) {
        return $configured;
    }

    // Auto-generate based on site URL
    $siteUrl = getSetting('site_url', '');
    if (!empty($siteUrl)) {
        return rtrim($siteUrl, '/');
    }

    // Fallback to current host
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $protocol . '://' . $host;
}

/**
 * Get SAML Assertion Consumer Service (ACS) URL
 */
function getSAMLACSUrl() {
    $configured = getSetting('saml_acs_url', '');
    if (!empty($configured)) {
        return $configured;
    }

    $siteUrl = getSetting('site_url', '');
    if (!empty($siteUrl) && getSetting('force_site_url', '0') === '1') {
        return rtrim($siteUrl, '/') . '/saml-acs';
    }

    // Auto-detect from request
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $protocol = $_SERVER['HTTP_X_FORWARDED_PROTO'];
    }

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    if (!empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
        $host = $_SERVER['HTTP_X_FORWARDED_HOST'];
    }

    $basePath = dirname($_SERVER['SCRIPT_NAME']);
    if ($basePath === '/' || $basePath === '\\') {
        $basePath = '';
    }

    return $protocol . '://' . $host . $basePath . '/saml-acs';
}

/**
 * Get the username attribute from SAML response
 */
function getSAMLUsernameAttribute() {
    return getSetting('saml_username_attribute', 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/name');
}

/**
 * Get the email attribute from SAML response
 */
function getSAMLEmailAttribute() {
    return getSetting('saml_email_attribute', 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress');
}

/**
 * Get the groups attribute from SAML response
 */
function getSAMLGroupsAttribute() {
    return getSetting('saml_groups_attribute', 'http://schemas.microsoft.com/ws/2008/06/identity/claims/groups');
}

/**
 * Check if auto-registration is enabled for new SAML users
 */
function isSAMLAutoRegisterEnabled() {
    return getSetting('saml_auto_register', '1') === '1';
}

/**
 * Fetch and parse IdP metadata from URL
 */
function fetchSAMLIdPMetadata($metadataUrl) {
    $ch = curl_init($metadataUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Accept: application/xml, text/xml',
            'User-Agent: Silo/1.0'
        ]
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        logError('SAML IdP metadata fetch failed', [
            'url' => $metadataUrl,
            'http_code' => $httpCode,
            'error' => $error
        ]);
        return null;
    }

    return parseSAMLIdPMetadata($response);
}

/**
 * Parse SAML IdP metadata XML
 */
function parseSAMLIdPMetadata($xml) {
    libxml_use_internal_errors(true);
    $doc = new DOMDocument();

    if (!$doc->loadXML($xml)) {
        logError('SAML metadata XML parse error');
        return null;
    }

    $xpath = new DOMXPath($doc);
    $xpath->registerNamespace('md', 'urn:oasis:names:tc:SAML:2.0:metadata');
    $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');

    $metadata = [
        'entity_id' => null,
        'sso_url' => null,
        'slo_url' => null,
        'certificate' => null,
        'name_id_format' => null
    ];

    // Get Entity ID
    $entityDesc = $xpath->query('//md:EntityDescriptor')->item(0);
    if ($entityDesc) {
        $metadata['entity_id'] = $entityDesc->getAttribute('entityID');
    }

    // Get SSO URL (HTTP-Redirect binding preferred)
    $ssoRedirect = $xpath->query("//md:IDPSSODescriptor/md:SingleSignOnService[@Binding='urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect']")->item(0);
    if ($ssoRedirect) {
        $metadata['sso_url'] = $ssoRedirect->getAttribute('Location');
    } else {
        $ssoPost = $xpath->query("//md:IDPSSODescriptor/md:SingleSignOnService[@Binding='urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST']")->item(0);
        if ($ssoPost) {
            $metadata['sso_url'] = $ssoPost->getAttribute('Location');
        }
    }

    // Get SLO URL
    $sloRedirect = $xpath->query("//md:IDPSSODescriptor/md:SingleLogoutService[@Binding='urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect']")->item(0);
    if ($sloRedirect) {
        $metadata['slo_url'] = $sloRedirect->getAttribute('Location');
    }

    // Get Certificate
    $certNode = $xpath->query("//md:IDPSSODescriptor/md:KeyDescriptor[@use='signing']/ds:KeyInfo/ds:X509Data/ds:X509Certificate")->item(0);
    if (!$certNode) {
        $certNode = $xpath->query("//md:IDPSSODescriptor/md:KeyDescriptor/ds:KeyInfo/ds:X509Data/ds:X509Certificate")->item(0);
    }
    if ($certNode) {
        $metadata['certificate'] = preg_replace('/\s+/', '', $certNode->textContent);
    }

    // Get NameID Format
    $nameIdFormat = $xpath->query("//md:IDPSSODescriptor/md:NameIDFormat")->item(0);
    if ($nameIdFormat) {
        $metadata['name_id_format'] = $nameIdFormat->textContent;
    }

    return $metadata;
}

/**
 * Get SAML IdP configuration
 */
function getSAMLIdPConfig() {
    // Check cache
    $cacheKey = 'saml_idp_config_cache';
    $cached = getSetting($cacheKey);

    if ($cached) {
        $data = json_decode($cached, true);
        if ($data && isset($data['expires']) && $data['expires'] > time()) {
            return $data['config'];
        }
    }

    // Check if metadata URL is configured
    $metadataUrl = getSetting('saml_idp_metadata_url', '');
    if (!empty($metadataUrl)) {
        $metadata = fetchSAMLIdPMetadata($metadataUrl);
        if ($metadata) {
            // Cache for 24 hours
            setSetting($cacheKey, json_encode([
                'config' => $metadata,
                'expires' => time() + 86400
            ]));
            return $metadata;
        }
    }

    // Fall back to manual configuration
    return [
        'entity_id' => getSetting('saml_idp_entity_id', ''),
        'sso_url' => getSetting('saml_idp_sso_url', ''),
        'slo_url' => getSetting('saml_idp_slo_url', ''),
        'certificate' => getSetting('saml_idp_certificate', ''),
        'name_id_format' => getSetting('saml_name_id_format', 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress')
    ];
}

/**
 * Clear SAML IdP config cache
 */
function clearSAMLConfigCache() {
    setSetting('saml_idp_config_cache', '');
}

/**
 * Generate SAML AuthnRequest
 */
function generateSAMLAuthnRequest() {
    $idpConfig = getSAMLIdPConfig();

    if (empty($idpConfig['sso_url'])) {
        return null;
    }

    $id = '_' . bin2hex(random_bytes(16));
    $issueInstant = gmdate('Y-m-d\TH:i:s\Z');
    $spEntityId = getSAMLSPEntityId();
    $acsUrl = getSAMLACSUrl();
    $nameIdFormat = $idpConfig['name_id_format'] ?? 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress';

    $request = <<<XML
<samlp:AuthnRequest xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol"
    xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion"
    ID="{$id}"
    Version="2.0"
    IssueInstant="{$issueInstant}"
    Destination="{$idpConfig['sso_url']}"
    AssertionConsumerServiceURL="{$acsUrl}"
    ProtocolBinding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST">
    <saml:Issuer>{$spEntityId}</saml:Issuer>
    <samlp:NameIDPolicy Format="{$nameIdFormat}" AllowCreate="true"/>
</samlp:AuthnRequest>
XML;

    // Store request ID for validation
    $_SESSION['saml_request_id'] = $id;

    return $request;
}

/**
 * Get SAML login URL
 */
function getSAMLAuthUrl($returnUrl = null) {
    $idpConfig = getSAMLIdPConfig();

    if (empty($idpConfig['sso_url'])) {
        logError('SAML auth URL failed: no SSO URL configured');
        return null;
    }

    $authnRequest = generateSAMLAuthnRequest();
    if (!$authnRequest) {
        return null;
    }

    // Store return URL
    if ($returnUrl) {
        $_SESSION['saml_return_url'] = $returnUrl;
    }

    // Generate relay state
    $relayState = bin2hex(random_bytes(16));
    $_SESSION['saml_relay_state'] = $relayState;

    // Encode and compress request for redirect binding
    $deflated = gzdeflate($authnRequest);
    $encoded = base64_encode($deflated);

    $params = [
        'SAMLRequest' => $encoded,
        'RelayState' => $relayState
    ];

    // Optionally sign the request
    if (getSetting('saml_sign_requests', '0') === '1') {
        $params = signSAMLRequest($params);
    }

    return $idpConfig['sso_url'] . '?' . http_build_query($params);
}

/**
 * Sign SAML request (optional)
 */
function signSAMLRequest($params) {
    $privateKey = getSetting('saml_sp_private_key', '');
    if (empty($privateKey)) {
        return $params;
    }

    $algorithm = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';
    $signatureAlg = OPENSSL_ALGO_SHA256;

    $query = 'SAMLRequest=' . urlencode($params['SAMLRequest']);
    if (isset($params['RelayState'])) {
        $query .= '&RelayState=' . urlencode($params['RelayState']);
    }
    $query .= '&SigAlg=' . urlencode($algorithm);

    $key = openssl_pkey_get_private($privateKey);
    if ($key) {
        openssl_sign($query, $signature, $key, $signatureAlg);
        $params['SigAlg'] = $algorithm;
        $params['Signature'] = base64_encode($signature);
    }

    return $params;
}

/**
 * Process SAML Response from IdP
 */
function processSAMLResponse($samlResponse) {
    // Base64 decode
    $xml = base64_decode($samlResponse);
    if (!$xml) {
        logError('SAML response decode failed');
        return ['error' => 'Invalid SAML response encoding'];
    }

    // Parse XML
    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    if (!$doc->loadXML($xml)) {
        logError('SAML response XML parse failed');
        return ['error' => 'Invalid SAML response format'];
    }

    $xpath = new DOMXPath($doc);
    $xpath->registerNamespace('samlp', 'urn:oasis:names:tc:SAML:2.0:protocol');
    $xpath->registerNamespace('saml', 'urn:oasis:names:tc:SAML:2.0:assertion');

    // Check for error response
    $status = $xpath->query('//samlp:Status/samlp:StatusCode')->item(0);
    if ($status) {
        $statusValue = $status->getAttribute('Value');
        if ($statusValue !== 'urn:oasis:names:tc:SAML:2.0:status:Success') {
            logError('SAML response status not success', ['status' => $statusValue]);
            return ['error' => 'Authentication failed: ' . basename($statusValue)];
        }
    }

    // Verify signature if certificate is configured
    $idpConfig = getSAMLIdPConfig();
    if (!empty($idpConfig['certificate'])) {
        if (!verifySAMLSignature($doc, $idpConfig['certificate'])) {
            logError('SAML signature verification failed');
            return ['error' => 'Signature verification failed'];
        }
    }

    // Extract assertion
    $assertion = $xpath->query('//saml:Assertion')->item(0);
    if (!$assertion) {
        logError('SAML assertion not found');
        return ['error' => 'No assertion in response'];
    }

    // Verify conditions (timestamps)
    $conditions = $xpath->query('//saml:Conditions')->item(0);
    if ($conditions) {
        $notBefore = $conditions->getAttribute('NotBefore');
        $notOnOrAfter = $conditions->getAttribute('NotOnOrAfter');
        $now = time();

        if ($notBefore && strtotime($notBefore) > $now + 300) {
            return ['error' => 'Assertion not yet valid'];
        }
        if ($notOnOrAfter && strtotime($notOnOrAfter) < $now - 300) {
            return ['error' => 'Assertion has expired'];
        }
    }

    // Verify InResponseTo
    $inResponseTo = $xpath->query('//samlp:Response/@InResponseTo')->item(0);
    if ($inResponseTo && isset($_SESSION['saml_request_id'])) {
        if ($inResponseTo->value !== $_SESSION['saml_request_id']) {
            logError('SAML InResponseTo mismatch');
            return ['error' => 'Response does not match request'];
        }
    }

    // Extract attributes
    $attributes = [];
    $attributeNodes = $xpath->query('//saml:AttributeStatement/saml:Attribute');

    foreach ($attributeNodes as $attr) {
        $name = $attr->getAttribute('Name');
        $values = [];

        $valueNodes = $xpath->query('saml:AttributeValue', $attr);
        foreach ($valueNodes as $valueNode) {
            $values[] = $valueNode->textContent;
        }

        $attributes[$name] = count($values) === 1 ? $values[0] : $values;
    }

    // Get NameID
    $nameId = $xpath->query('//saml:Subject/saml:NameID')->item(0);
    $nameIdValue = $nameId ? $nameId->textContent : null;
    $nameIdFormat = $nameId ? $nameId->getAttribute('Format') : null;

    // Clear session variables
    unset($_SESSION['saml_request_id']);
    unset($_SESSION['saml_relay_state']);

    return [
        'success' => true,
        'name_id' => $nameIdValue,
        'name_id_format' => $nameIdFormat,
        'attributes' => $attributes,
        'session_index' => $xpath->query('//saml:AuthnStatement/@SessionIndex')->item(0)?->value
    ];
}

/**
 * Verify SAML signature
 */
function verifySAMLSignature($doc, $certificate) {
    // Format certificate
    $cert = "-----BEGIN CERTIFICATE-----\n" .
            chunk_split($certificate, 64, "\n") .
            "-----END CERTIFICATE-----";

    $xpath = new DOMXPath($doc);
    $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');

    $signatureNode = $xpath->query('//ds:Signature')->item(0);
    if (!$signatureNode) {
        // No signature to verify
        return getSetting('saml_require_signature', '0') !== '1';
    }

    // Get signed info
    $signedInfoNode = $xpath->query('ds:SignedInfo', $signatureNode)->item(0);
    $signatureValueNode = $xpath->query('ds:SignatureValue', $signatureNode)->item(0);

    if (!$signedInfoNode || !$signatureValueNode) {
        return false;
    }

    // Canonicalize SignedInfo
    $signedInfo = $signedInfoNode->C14N(true, false);
    $signature = base64_decode($signatureValueNode->textContent);

    // Get signature algorithm
    $signatureMethod = $xpath->query('ds:SignedInfo/ds:SignatureMethod/@Algorithm', $signatureNode)->item(0);
    $algorithm = $signatureMethod ? $signatureMethod->value : 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';

    // Map algorithm to OpenSSL constant
    $algMap = [
        'http://www.w3.org/2000/09/xmldsig#rsa-sha1' => OPENSSL_ALGO_SHA1,
        'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256' => OPENSSL_ALGO_SHA256,
        'http://www.w3.org/2001/04/xmldsig-more#rsa-sha384' => OPENSSL_ALGO_SHA384,
        'http://www.w3.org/2001/04/xmldsig-more#rsa-sha512' => OPENSSL_ALGO_SHA512
    ];

    $opensslAlg = $algMap[$algorithm] ?? OPENSSL_ALGO_SHA256;

    // Verify
    $pubKey = openssl_pkey_get_public($cert);
    if (!$pubKey) {
        logError('SAML invalid certificate');
        return false;
    }

    return openssl_verify($signedInfo, $signature, $pubKey, $opensslAlg) === 1;
}

/**
 * Find or create user from SAML response
 */
function findOrCreateSAMLUser($samlData) {
    $db = getDB();

    // Extract attributes
    $attributes = $samlData['attributes'] ?? [];
    $nameId = $samlData['name_id'] ?? null;

    // Get username
    $usernameAttr = getSAMLUsernameAttribute();
    $username = $attributes[$usernameAttr] ?? null;
    if (is_array($username)) $username = $username[0];

    // Fallback to NameID
    if (empty($username) && $nameId) {
        $username = $nameId;
    }

    if (empty($username)) {
        return ['error' => 'No username in SAML response'];
    }

    // Get email
    $emailAttr = getSAMLEmailAttribute();
    $email = $attributes[$emailAttr] ?? null;
    if (is_array($email)) $email = $email[0];

    // Sanitize username
    $username = preg_replace('/[^a-zA-Z0-9_.-]/', '', $username);
    if (empty($username)) {
        $username = 'saml_user_' . substr(md5($nameId), 0, 8);
    }

    // Try to find existing user by SAML ID
    $stmt = $db->prepare('SELECT * FROM users WHERE saml_id = :saml_id');
    $stmt->bindValue(':saml_id', $nameId, PDO::PARAM_STR);
    $existingUser = $stmt->execute()->fetchArray(PDO::FETCH_ASSOC);

    if ($existingUser) {
        // Update attributes
        $stmt = $db->prepare('
            UPDATE users
            SET saml_attributes = :attrs, last_auth_at = :now, auth_method = :method
            WHERE id = :id
        ');
        $stmt->bindValue(':attrs', json_encode($attributes), PDO::PARAM_STR);
        $stmt->bindValue(':now', date('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->bindValue(':method', 'saml', PDO::PARAM_STR);
        $stmt->bindValue(':id', $existingUser['id'], PDO::PARAM_INT);
        $stmt->execute();

        // Sync groups if enabled
        if (getSetting('saml_sync_groups', '0') === '1') {
            syncSAMLGroups($existingUser['id'], $attributes);
        }

        return ['success' => true, 'user' => $existingUser, 'is_new' => false];
    }

    // Try to find by username or email
    $stmt = $db->prepare('SELECT * FROM users WHERE username = :username OR email = :email');
    $stmt->bindValue(':username', $username, PDO::PARAM_STR);
    $stmt->bindValue(':email', $email ?? '', PDO::PARAM_STR);
    $existingUser = $stmt->execute()->fetchArray(PDO::FETCH_ASSOC);

    if ($existingUser) {
        // Link existing account to SAML
        $stmt = $db->prepare('
            UPDATE users
            SET saml_id = :saml_id, saml_attributes = :attrs, last_auth_at = :now, auth_method = :method
            WHERE id = :id
        ');
        $stmt->bindValue(':saml_id', $nameId, PDO::PARAM_STR);
        $stmt->bindValue(':attrs', json_encode($attributes), PDO::PARAM_STR);
        $stmt->bindValue(':now', date('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->bindValue(':method', 'saml', PDO::PARAM_STR);
        $stmt->bindValue(':id', $existingUser['id'], PDO::PARAM_INT);
        $stmt->execute();

        if (getSetting('saml_sync_groups', '0') === '1') {
            syncSAMLGroups($existingUser['id'], $attributes);
        }

        return ['success' => true, 'user' => $existingUser, 'is_new' => false];
    }

    // Create new user if auto-register is enabled
    if (!isSAMLAutoRegisterEnabled()) {
        return ['error' => 'User not found and auto-registration is disabled'];
    }

    // Get default group
    $defaultGroupId = getSetting('saml_default_group', getSetting('default_group', 1));

    // Create user
    $stmt = $db->prepare('
        INSERT INTO users (username, email, saml_id, saml_attributes, is_admin, auth_method, created_at)
        VALUES (:username, :email, :saml_id, :attrs, 0, :method, :now)
    ');
    $stmt->bindValue(':username', $username, PDO::PARAM_STR);
    $stmt->bindValue(':email', $email, PDO::PARAM_STR);
    $stmt->bindValue(':saml_id', $nameId, PDO::PARAM_STR);
    $stmt->bindValue(':attrs', json_encode($attributes), PDO::PARAM_STR);
    $stmt->bindValue(':method', 'saml', PDO::PARAM_STR);
    $stmt->bindValue(':now', date('Y-m-d H:i:s'), PDO::PARAM_STR);
    $stmt->execute();

    $userId = $db->lastInsertRowID();

    // Assign to default group
    if ($defaultGroupId) {
        $stmt = $db->prepare('INSERT OR IGNORE INTO user_groups (user_id, group_id) VALUES (:uid, :gid)');
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':gid', $defaultGroupId, PDO::PARAM_INT);
        $stmt->execute();
    }

    // Sync groups if enabled
    if (getSetting('saml_sync_groups', '0') === '1') {
        syncSAMLGroups($userId, $attributes);
    }

    // Get the created user
    $stmt = $db->prepare('SELECT * FROM users WHERE id = :id');
    $stmt->bindValue(':id', $userId, PDO::PARAM_INT);
    $newUser = $stmt->execute()->fetchArray(PDO::FETCH_ASSOC);

    logActivity($userId, 'saml_register', 'user', $userId, 'New SAML user: ' . $username);

    return ['success' => true, 'user' => $newUser, 'is_new' => true];
}

/**
 * Sync SAML groups to Silo groups
 */
function syncSAMLGroups($userId, $attributes) {
    $groupsAttr = getSAMLGroupsAttribute();
    $groups = $attributes[$groupsAttr] ?? [];

    if (!is_array($groups)) {
        $groups = [$groups];
    }

    if (empty($groups)) {
        return;
    }

    // Get group mapping
    $mappingJson = getSetting('saml_group_mapping', '{}');
    $mapping = json_decode($mappingJson, true) ?: [];

    if (empty($mapping)) {
        return;
    }

    $db = getDB();

    // Get current groups
    $stmt = $db->prepare('SELECT group_id FROM user_groups WHERE user_id = :uid');
    $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
    $result = $stmt->execute();
    $currentGroups = [];
    while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
        $currentGroups[] = $row['group_id'];
    }

    // Determine target groups from mapping
    $targetGroups = [];
    foreach ($groups as $samlGroup) {
        if (isset($mapping[$samlGroup])) {
            $targetGroups[] = (int)$mapping[$samlGroup];
        }
    }

    // Add missing groups
    foreach ($targetGroups as $groupId) {
        if (!in_array($groupId, $currentGroups)) {
            $stmt = $db->prepare('INSERT OR IGNORE INTO user_groups (user_id, group_id) VALUES (:uid, :gid)');
            $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':gid', $groupId, PDO::PARAM_INT);
            $stmt->execute();
        }
    }

    // Optionally remove groups not in SAML
    if (getSetting('saml_remove_unmapped_groups', '0') === '1') {
        foreach ($currentGroups as $groupId) {
            if (!in_array($groupId, $targetGroups)) {
                $stmt = $db->prepare('DELETE FROM user_groups WHERE user_id = :uid AND group_id = :gid');
                $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
                $stmt->bindValue(':gid', $groupId, PDO::PARAM_INT);
                $stmt->execute();
            }
        }
    }
}

/**
 * Generate SP Metadata XML
 */
function generateSPMetadata() {
    $entityId = getSAMLSPEntityId();
    $acsUrl = getSAMLACSUrl();
    $nameIdFormat = getSetting('saml_name_id_format', 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress');

    $spCert = getSetting('saml_sp_certificate', '');
    $certXml = '';
    if (!empty($spCert)) {
        $certClean = preg_replace('/\s+/', '', $spCert);
        $certXml = <<<XML
    <md:KeyDescriptor use="signing">
      <ds:KeyInfo xmlns:ds="http://www.w3.org/2000/09/xmldsig#">
        <ds:X509Data>
          <ds:X509Certificate>{$certClean}</ds:X509Certificate>
        </ds:X509Data>
      </ds:KeyInfo>
    </md:KeyDescriptor>
XML;
    }

    $metadata = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" entityID="{$entityId}">
  <md:SPSSODescriptor AuthnRequestsSigned="false" WantAssertionsSigned="true" protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">
{$certXml}
    <md:NameIDFormat>{$nameIdFormat}</md:NameIDFormat>
    <md:AssertionConsumerService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST" Location="{$acsUrl}" index="0" isDefault="true"/>
  </md:SPSSODescriptor>
</md:EntityDescriptor>
XML;

    return $metadata;
}
