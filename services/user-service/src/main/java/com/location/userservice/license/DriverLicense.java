package com.location.userservice.license;

import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.Id;
import jakarta.persistence.Table;
import java.time.LocalDate;
import java.util.UUID;

/** Permis de conduire (un seul par utilisateur ; userId sans relation JPA). */
@Entity
@Table(name = "driver_licenses")
public class DriverLicense {

    @Id
    @Column(length = 36)
    private UUID id;

    @Column(nullable = false, unique = true, length = 30)
    private String number;

    @Column(nullable = false, length = 10)
    private String category;

    @Column(nullable = false)
    private LocalDate issueDate;

    @Column(nullable = false)
    private LocalDate expiryDate;

    @Column(nullable = false)
    private boolean verified;

    @Column(nullable = false, unique = true, length = 36)
    private UUID userId;

    protected DriverLicense() {
    }

    public DriverLicense(UUID id, UUID userId) {
        this.id = id;
        this.userId = userId;
    }

    public boolean isExpiredOn(LocalDate day) {
        return expiryDate.isBefore(day);
    }

    public UUID getId() { return id; }
    public String getNumber() { return number; }
    public void setNumber(String number) { this.number = number; }
    public String getCategory() { return category; }
    public void setCategory(String category) { this.category = category; }
    public LocalDate getIssueDate() { return issueDate; }
    public void setIssueDate(LocalDate issueDate) { this.issueDate = issueDate; }
    public LocalDate getExpiryDate() { return expiryDate; }
    public void setExpiryDate(LocalDate expiryDate) { this.expiryDate = expiryDate; }
    public boolean isVerified() { return verified; }
    public void setVerified(boolean verified) { this.verified = verified; }
    public UUID getUserId() { return userId; }
}
