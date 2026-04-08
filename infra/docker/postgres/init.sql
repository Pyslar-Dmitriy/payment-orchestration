-- Application databases (one per service — enforces service-owned DB boundaries)
CREATE DATABASE merchant_api;
CREATE DATABASE payment_domain;
CREATE DATABASE payment_orchestrator;
CREATE DATABASE provider_gateway;
CREATE DATABASE webhook_ingest;
CREATE DATABASE webhook_normalizer;
CREATE DATABASE ledger_service;
CREATE DATABASE merchant_callback_delivery;
CREATE DATABASE reporting_projection;

-- Test databases (mirrors of the above — used by phpunit, never touched in dev)
CREATE DATABASE merchant_api_test;
CREATE DATABASE payment_domain_test;
CREATE DATABASE payment_orchestrator_test;
CREATE DATABASE provider_gateway_test;
CREATE DATABASE webhook_ingest_test;
CREATE DATABASE webhook_normalizer_test;
CREATE DATABASE ledger_service_test;
CREATE DATABASE merchant_callback_delivery_test;
CREATE DATABASE reporting_projection_test;

-- Temporal databases (auto-setup image runs schema migrations against these)
CREATE DATABASE temporal;
CREATE DATABASE temporal_visibility;
