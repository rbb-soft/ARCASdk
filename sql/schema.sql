-- =============================================================================
-- Rbbsoft\ArcaSdk - Schema SQL
-- =============================================================================
-- Ejecutar con `php db/migrate.php` (idempotente) o pegar en cliente MySQL.
--
-- Compatible con MariaDB >= 10.4 y MySQL >= 8.0.
-- En motores sin soporte JSON nativo se usa LONGTEXT con validacion en
-- capa de aplicacion. La migracion detecta motor/columna y ajusta.
--
-- Decisiones clave:
--   * Timestamps tecnicos en UTC (DATETIME, sin franja horaria).
--   * request_fingerprint CHAR(64) - SHA-256 hex de los datos de negocio
--     normalizados, excluyendo CbteNro y CbteFch.
--   * lease_token CHAR(36) - UUID v4 renovado en cada INSERT/reapertura;
--     solo el worker ganador puede mutar la fila.
--   * cbte_nro_enviado / cbte_fch_enviado: evidencia del intento, no
--     reserva fiscal. Persistidos ANTES de llamar a ARCA para que una
--     recuperacion zombie nunca calcule silenciosamente otro comprobante.
--   * arca_ticket_acceso.source VARCHAR(32) NULL: origen del TA persistido
--     ('wsfe' homologacion, 'wsaa' produccion, 'cache' lectura de cache,
--     'memory' NullTicketCache, 'mysql' MysqlTicketCache). Solo
--     diagnostico. Idempotente: el ALTER siguiente agrega la columna a
--     deployments previos que aun no la tengan.
-- =============================================================================

CREATE TABLE IF NOT EXISTS arca_ticket_acceso (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cuit            CHAR(11)        NOT NULL,
    wsn             VARCHAR(64)     NOT NULL,
    token           TEXT            NOT NULL,
    sign            TEXT            NOT NULL,
    expiration_time DATETIME        NOT NULL COMMENT 'UTC; evaluar vigencia con margen en PHP',
    source          VARCHAR(32)     NULL     COMMENT 'Origen del TA: wsfe/wsaa/cache/memory/mysql',
    created_at      DATETIME        NOT NULL,
    updated_at      DATETIME        NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_cuit_wsn (cuit, wsn)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Idempotente: agrega la columna `source` a deployments que se hicieron
-- antes de que existiera. El migrador trata SQLSTATE 42S21 (columna
-- duplicada) como skip para que re-ejecuciones sean no-op.
ALTER TABLE arca_ticket_acceso ADD COLUMN source VARCHAR(32) NULL COMMENT 'Origen del TA: wsfe/wsaa/cache/memory/mysql';

-- -----------------------------------------------------------------------------
-- Emisiones idempotentes
-- -----------------------------------------------------------------------------
-- Estados:
--   en_curso  - lease vigente, worker trabajando. Renueva lease_token.
--   emitido   - CAE/numero confirmados. Lease liberado.
--   fallido   - rechazo funcional o infraestructura. Reabrible si
--               intento < idempotencia_max_intentos o es_fallo_infra=1.
--
-- es_fallo_infra:
--   0 - rechazo funcional / validacion / estado incoherente. Suma intento.
--   1 - fallo de red/SOAP/5xx/9999/timeout. NO suma intento al reabrir.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS arca_emisiones_idempotencia (
    external_id          CHAR(36)        NOT NULL COMMENT 'UUID v4 entregado por el caller',
    cuit                 CHAR(11)        NOT NULL,
    punto_venta          INT UNSIGNED    NOT NULL,
    cbte_tipo            INT UNSIGNED    NOT NULL COMMENT 'Tipo ARCA: 1,2,3,6,7,8,11,12,13,51,53',
    estado               ENUM('en_curso','emitido','fallido') NOT NULL,
    lease_token          CHAR(36)        NULL     COMMENT 'UUID v4 del worker activo; NULL en estado terminal',
    intento              INT UNSIGNED    NOT NULL DEFAULT 0,
    es_fallo_infra       TINYINT(1)      NOT NULL DEFAULT 0,
    request_fingerprint  CHAR(64)        NOT NULL COMMENT 'SHA-256 hex de canonical_json (sin CbteNro/CbteFch)',
    request_json         LONGTEXT        NOT NULL COMMENT 'Snapshot canonico inmutable de la solicitud enviada a ARCA',
    cbte_nro_enviado     INT UNSIGNED    NULL     COMMENT 'Numero reservado antes de la primera llamada; NULL si nunca se llego a enviar',
    cbte_fch_enviado     DATE            NULL     COMMENT 'Fecha civil argentina (YYYY-MM-DD) enviada a ARCA',
    cae                  VARCHAR(14)     NULL,
    cae_fch_vto          DATE            NULL,
    cbte_nro_confirmado  INT UNSIGNED    NULL,
    response_json        LONGTEXT        NULL     COMMENT 'Snapshot de la respuesta ARCA o error serializado',
    created_at           DATETIME        NOT NULL,
    updated_at           DATETIME        NOT NULL,
    PRIMARY KEY (external_id),
    KEY idx_estado_updated (estado, updated_at),
    KEY idx_cuit_pv_tipo (cuit, punto_venta, cbte_tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
