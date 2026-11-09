package com.location.userservice.address;

import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.Id;
import jakarta.persistence.Table;
import java.util.UUID;

/** Adresse d'un utilisateur (userId = id de l'utilisateur, sans relation JPA). */
@Entity
@Table(name = "addresses")
public class Address {

    @Id
    @Column(length = 36)
    private UUID id;

    @Column(nullable = false)
    private String street;

    @Column(nullable = false, length = 100)
    private String city;

    @Column(nullable = false, length = 10)
    private String zipCode;

    @Column(nullable = false, length = 36)
    private UUID userId;

    protected Address() {
    }

    public Address(UUID id, UUID userId) {
        this.id = id;
        this.userId = userId;
    }

    public UUID getId() { return id; }
    public String getStreet() { return street; }
    public void setStreet(String street) { this.street = street; }
    public String getCity() { return city; }
    public void setCity(String city) { this.city = city; }
    public String getZipCode() { return zipCode; }
    public void setZipCode(String zipCode) { this.zipCode = zipCode; }
    public UUID getUserId() { return userId; }
}
