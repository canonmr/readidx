-- Schema for storing XBRL taxonomy metadata, contexts, units, and facts
CREATE TABLE IF NOT EXISTS xbrl_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_name VARCHAR(255) NOT NULL,
    document_hash CHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_xbrl_documents_hash (document_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX IF NOT EXISTS idx_xbrl_documents_name ON xbrl_documents (document_name);

CREATE TABLE IF NOT EXISTS xbrl_taxonomy_concepts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    namespace VARCHAR(512) NOT NULL,
    local_name VARCHAR(255) NOT NULL,
    qname VARCHAR(255) NOT NULL,
    id_attr VARCHAR(255) NULL,
    substitution_group VARCHAR(255) NULL,
    type VARCHAR(255) NULL,
    period_type VARCHAR(32) NULL,
    balance VARCHAR(32) NULL,
    abstract_flag TINYINT(1) NOT NULL DEFAULT 0,
    nillable_flag TINYINT(1) NOT NULL DEFAULT 1,
    documentation TEXT NULL,
    UNIQUE KEY uq_xbrl_concepts_namespace_local (namespace, local_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS xbrl_taxonomy_linkbases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL,
    target_namespace VARCHAR(512) NULL,
    href TEXT NOT NULL,
    role VARCHAR(512) NULL,
    arcrole VARCHAR(512) NULL,
    linkbase_type VARCHAR(128) NULL,
    FOREIGN KEY (document_id) REFERENCES xbrl_documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS xbrl_taxonomy_role_refs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL,
    role_uri VARCHAR(512) NOT NULL,
    href TEXT NULL,
    FOREIGN KEY (document_id) REFERENCES xbrl_documents(id) ON DELETE CASCADE,
    UNIQUE KEY uq_xbrl_role_refs_doc_role (document_id, role_uri(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS xbrl_contexts (
    document_id INT NOT NULL,
    context_id VARCHAR(128) NOT NULL,
    entity_identifier VARCHAR(512) NULL,
    entity_scheme VARCHAR(512) NULL,
    period_type ENUM('duration','instant','forever') NULL,
    start_date DATE NULL,
    end_date DATE NULL,
    instant DATE NULL,
    segment_json LONGTEXT NULL,
    scenario_json LONGTEXT NULL,
    PRIMARY KEY (document_id, context_id),
    FOREIGN KEY (document_id) REFERENCES xbrl_documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX IF NOT EXISTS idx_xbrl_contexts_entity ON xbrl_contexts (entity_identifier(255));
CREATE INDEX IF NOT EXISTS idx_xbrl_contexts_period ON xbrl_contexts (period_type, start_date, end_date, instant);

CREATE TABLE IF NOT EXISTS xbrl_context_dimensions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL,
    context_id VARCHAR(128) NOT NULL,
    location ENUM('segment','scenario') NOT NULL,
    dimension VARCHAR(512) NOT NULL,
    member VARCHAR(512) NULL,
    is_typed TINYINT(1) NOT NULL DEFAULT 0,
    typed_member_xml LONGTEXT NULL,
    FOREIGN KEY (document_id, context_id) REFERENCES xbrl_contexts(document_id, context_id) ON DELETE CASCADE,
    INDEX idx_xbrl_ctx_dims_ctx (document_id, context_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS xbrl_units (
    document_id INT NOT NULL,
    unit_id VARCHAR(128) NOT NULL,
    unit_type ENUM('measure','divide') NOT NULL,
    measures_json LONGTEXT NOT NULL,
    PRIMARY KEY (document_id, unit_id),
    FOREIGN KEY (document_id) REFERENCES xbrl_documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS xbrl_facts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL,
    concept_id INT NULL,
    concept_namespace VARCHAR(512) NOT NULL,
    concept_local_name VARCHAR(255) NOT NULL,
    concept_qname VARCHAR(255) NOT NULL,
    context_id VARCHAR(128) NOT NULL,
    unit_id VARCHAR(128) NULL,
    value_decimal DECIMAL(36,10) NULL,
    value_string LONGTEXT NULL,
    decimals_attr VARCHAR(32) NULL,
    precision_attr VARCHAR(32) NULL,
    language VARCHAR(32) NULL,
    is_nil TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (concept_id) REFERENCES xbrl_taxonomy_concepts(id) ON DELETE SET NULL,
    FOREIGN KEY (document_id, context_id) REFERENCES xbrl_contexts(document_id, context_id) ON DELETE CASCADE,
    FOREIGN KEY (document_id, unit_id) REFERENCES xbrl_units(document_id, unit_id) ON DELETE SET NULL,
    FOREIGN KEY (document_id) REFERENCES xbrl_documents(id) ON DELETE CASCADE,
    INDEX idx_xbrl_facts_document (document_id),
    INDEX idx_xbrl_facts_concept (concept_namespace(255), concept_local_name(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX IF NOT EXISTS idx_xbrl_facts_context ON xbrl_facts (document_id, context_id);
