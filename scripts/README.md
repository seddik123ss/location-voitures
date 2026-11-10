# scripts

Scripts d integration locale (PowerShell 5.1) : voir docs/integration-locale.md.

- start-all.ps1 : verifie la machine, lance infra + 7 services + front (une fenetre par processus), controle Eureka et RabbitMQ
- stop-all.ps1 : ferme ces fenetres, nettoie Eureka (-Infra : arrete aussi les conteneurs)
- reset-demo.ps1 : remet les donnees de demo a zero (services arretes)
- integration-tools.ps1 : fonctions communes (PHP 8.4 via -PhpHome ou PHP84_HOME, JDK via -JavaHome ou JDK21_HOME)
