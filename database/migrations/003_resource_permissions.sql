-- MIGRAÇÃO HISTÓRICA DESATIVADA
--
-- O modelo definitivo do DocGov usa `permissions`, criado por
-- `003_permissions_model.sql`. Este arquivo é mantido somente para que rotinas
-- antigas que enumeram todos os .sql não recriem a tabela concorrente
-- `resource_permissions` nem removam `group_access` antes da consolidação.
--
-- Dados de instalações que chegaram a usar a tabela antiga são reconciliados
-- por `011_access_model_hardening.sql`.
DO $$
BEGIN
    RAISE NOTICE '003_resource_permissions.sql é histórico; usando o modelo canônico permissions.';
END $$;
