# Fresh Deployment Security Checklist

- [ ] `.env` is not tracked by Git
- [ ] Production secrets were generated/rotated
- [ ] Application is HTTPS-only
- [ ] Admin password changed
- [ ] Database is not publicly exposed
- [ ] Internal RAG endpoint/token is protected
- [ ] Widget origin allow-list contains exact trusted origins only
- [ ] Unauthorized widget origin test returns HTTP 403
- [ ] Widget ON/OFF tested
- [ ] Widget Voice ON/OFF tested
- [ ] Public Chat ON/OFF tested
- [ ] Backups configured
- [ ] Restore procedure documented/tested
- [ ] Debug mode disabled for production
