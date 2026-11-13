package com.location.loyaltyservice.messaging;

import org.springframework.amqp.core.Declarables;
import org.springframework.amqp.core.Queue;
import org.springframework.amqp.core.QueueBuilder;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Configuration;

/**
 * Files consommées par loyalty-service, déclarées par lui (règle d'équipe : le consommateur déclare sa file).
 * Chacune est durable et envoie ses messages rejetés dans &lt;file&gt;.dlq (exchange par défaut,
 * mêmes arguments que les autres services : x-dead-letter-exchange "" et x-dead-letter-routing-key &lt;file&gt;.dlq).
 * La déclaration a lieu à la première connexion à RabbitMQ.
 */
@Configuration
public class RabbitConfig {

    public static final String USER_EVENTS = "loyalty.user_events";
    public static final String RESERVATION_COMPLETED = "loyalty.reservation_completed";

    @Bean
    public Declarables loyaltyQueues() {
        return new Declarables(
                deadLetterQueue(USER_EVENTS), queue(USER_EVENTS),
                deadLetterQueue(RESERVATION_COMPLETED), queue(RESERVATION_COMPLETED));
    }

    private static Queue queue(String name) {
        return QueueBuilder.durable(name)
                .deadLetterExchange("")
                .deadLetterRoutingKey(name + ".dlq")
                .build();
    }

    private static Queue deadLetterQueue(String name) {
        return QueueBuilder.durable(name + ".dlq").build();
    }
}
