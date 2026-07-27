-- 'instagram' joins the credential services.
--
-- Meta ships two Instagram APIs. An app set up with the "Manage messaging &
-- content on Instagram" use case (permissions named instagram_business_*)
-- issues an INSTAGRAM USER token used against graph.instagram.com, which the
-- Page token on meta_graph cannot stand in for -- send one to the other and
-- the call is rejected. So the two tokens are two credentials.
--
-- api_credentials.service is an ENUM, so a new service is a schema change, not
-- just a constant: without this, storing the token writes an empty string on a
-- permissive server (a silent, baffling "credential saved but never active")
-- and errors outright on a strict one.
--
-- App\Integrations\Meta\InstagramApi picks the path: an active 'instagram'
-- credential wins, otherwise Instagram keeps running on the meta_graph Page
-- token exactly as before.

ALTER TABLE api_credentials
    MODIFY service ENUM(
        'whatsapp', 'anthropic', 'gemini', 'meta_graph', 'instagram',
        'openai', 'openrouter', 'tiktok'
    ) NOT NULL;
