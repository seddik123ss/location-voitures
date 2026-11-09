package com.location.userservice.address;

import com.location.userservice.address.dto.AddressRequest;
import com.location.userservice.address.dto.AddressResponse;
import com.location.userservice.common.ApiException;
import com.location.userservice.user.UserService;
import java.util.List;
import java.util.UUID;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

@Service
@Transactional(readOnly = true)
public class AddressService {

    private final AddressRepository addresses;
    private final UserService users;

    public AddressService(AddressRepository addresses, UserService users) {
        this.addresses = addresses;
        this.users = users;
    }

    public List<AddressResponse> list(UUID userId) {
        users.require(userId);
        return addresses.findByUserIdOrderByCityAscStreetAsc(userId).stream().map(AddressResponse::from).toList();
    }

    public AddressResponse get(UUID userId, UUID addressId) {
        return AddressResponse.from(require(userId, addressId));
    }

    @Transactional
    public AddressResponse create(UUID userId, AddressRequest request) {
        users.require(userId);
        Address address = new Address(UUID.randomUUID(), userId);
        apply(address, request);
        return AddressResponse.from(addresses.save(address));
    }

    @Transactional
    public AddressResponse update(UUID userId, UUID addressId, AddressRequest request) {
        Address address = require(userId, addressId);
        apply(address, request);
        return AddressResponse.from(addresses.save(address));
    }

    @Transactional
    public void delete(UUID userId, UUID addressId) {
        addresses.delete(require(userId, addressId));
    }

    private Address require(UUID userId, UUID addressId) {
        users.require(userId);
        return addresses.findByIdAndUserId(addressId, userId)
                .orElseThrow(() -> ApiException.notFound("Adresse introuvable pour cet utilisateur : " + addressId));
    }

    private static void apply(Address address, AddressRequest request) {
        address.setStreet(request.street().trim());
        address.setCity(request.city().trim());
        address.setZipCode(request.zipCode().trim());
    }
}
