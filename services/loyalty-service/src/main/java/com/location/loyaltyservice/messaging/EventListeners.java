package com.location.loyaltyservice.messaging;

import com.location.loyaltyservice.common.CorrelationId;
import com.rabbitmq.client.Channel;
import java.io.IOException;
import org.springframework.amqp.core.Message;
import org.springframework.amqp.rabbit.annotation.RabbitListener;
import org.springframework.stereotype.Component;

/**
 * Écoute des 2 files (ack manuel, prefetch 1 : application.yml). La décision vient de {@link EventProcessor} :
 * ACK = basicAck ; REQUEUE = basicNack avec remise en file ; DEAD_LETTER = basicNack sans remise,
 * donc RabbitMQ envoie le message dans &lt;file&gt;.dlq (x-dead-letter-routing-key).
 */
@Component
public class EventListeners {

    private final EventProcessor processor;

    public EventListeners(EventProcessor processor) {
        this.processor = processor;
    }

    @RabbitListener(queues = RabbitConfig.USER_EVENTS)
    public void onUserEvent(Message message, Channel channel) throws IOException {
        handle(RabbitConfig.USER_EVENTS, message, channel);
    }

    @RabbitListener(queues = RabbitConfig.RESERVATION_COMPLETED)
    public void onReservationCompleted(Message message, Channel channel) throws IOException {
        handle(RabbitConfig.RESERVATION_COMPLETED, message, channel);
    }

    private void handle(String queue, Message message, Channel channel) throws IOException {
        long tag = message.getMessageProperties().getDeliveryTag();
        Object correlation = message.getMessageProperties().getHeaders().get(CorrelationId.HEADER);
        CorrelationId.setFromMessage(correlation == null ? null : correlation.toString());
        try {
            Boolean redelivered = message.getMessageProperties().getRedelivered();
            Outcome outcome = processor.process(queue, message.getBody(), Boolean.TRUE.equals(redelivered));
            switch (outcome) {
                case ACK -> channel.basicAck(tag, false);
                case REQUEUE -> channel.basicNack(tag, false, true);
                case DEAD_LETTER -> channel.basicNack(tag, false, false);
            }
        } finally {
            CorrelationId.clear();
        }
    }
}
