-- Darak — privileges of the application role. Applied by deploy/deploy.sh after
-- every migration run, as the OWNER role:
--   psql -v app_role=darak_app -f deploy/postgres/app-grants.sql
-- Idempotent. Default privileges (provision-ubuntu.sh) cover tables created by
-- future migrations; this file also covers anything created before them.

SELECT format('GRANT USAGE ON SCHEMA public TO %I', :'app_role') \gexec
SELECT format('GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO %I', :'app_role') \gexec
SELECT format('GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO %I', :'app_role') \gexec
SELECT format('GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA public TO %I', :'app_role') \gexec

-- The audit log is append-only for the application, beneath the triggers too:
-- even a bug or an injected statement running as the app role cannot rewrite it.
SELECT format('REVOKE UPDATE, DELETE, TRUNCATE ON public.audit_logs FROM %I', :'app_role')
WHERE to_regclass('public.audit_logs') IS NOT NULL \gexec
