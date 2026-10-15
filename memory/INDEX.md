# iDive BCN Prod - Índice de memorias

## Memorias
- [arquitectura.md](arquitectura.md) - Stack, containers, credenciales, redes
- [bugs.md](bugs.md) - Bugs y soluciones (bono de regalo, PDFs)
- [calendario-cierres.md](calendario-cierres.md) - Calendario de cierres: medido en iDive, listo para implementar

## Memoria compartida con SuperDive (15-oct-2026)
Las dos webs se trabajan juntas a menudo, así que **lo que se aprende en una se escribe también
en la otra**. Contraparte: `/opt/wp-superdive-dev/memory/`. Lo transversal va al vault
(`/opt/obsidian-vault/40-runbooks/`), y cada proyecto apunta allí en vez de duplicar texto.

## Estado
- Migrado de VPS Contabo viejo (194.163.173.218) → VPS Netcup (152.53.127.100): 2026-05-15
- Producción funcional en patrón nuevo
- VPS viejo: containers parados (no destruidos)
- Repo GitHub: `Mikowsky-dev/idivebcn` (rama `dev` aquí, `main` en producción)
- Pendiente: cerrar el dev (sin basic auth ni guards), headers de seguridad, y aplicar el Calendario de cierres
