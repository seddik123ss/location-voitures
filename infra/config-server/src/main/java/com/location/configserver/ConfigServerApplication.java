package com.location.configserver;

import org.springframework.boot.SpringApplication;
import org.springframework.boot.autoconfigure.SpringBootApplication;
import org.springframework.cloud.config.server.EnableConfigServer;

/**
 * Config Server : sert la configuration NON secrete des services depuis infra/config-repo
 * (profil native). Exemple : GET /rental-ml/default -> application.yml + rental-ml.yml.
 * Les secrets restent dans le .env de chaque service.
 */
@SpringBootApplication
@EnableConfigServer
public class ConfigServerApplication {

    public static void main(String[] args) {
        SpringApplication.run(ConfigServerApplication.class, args);
    }
}
