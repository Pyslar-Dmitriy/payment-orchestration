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

-- Temporal databases (auto-setup image runs schema migrations against these)
CREATE DATABASE temporal;
CREATE DATABASE temporal_visibility;
