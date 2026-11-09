-- CreateTable
CREATE TABLE `categories` (
    `id` CHAR(36) NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `dailyRate` DECIMAL(10, 3) NOT NULL,
    `deposit` DECIMAL(10, 3) NOT NULL,
    `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updatedAt` DATETIME(3) NOT NULL,

    UNIQUE INDEX `categories_name_key`(`name`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `agencies` (
    `id` CHAR(36) NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `city` VARCHAR(100) NOT NULL,
    `address` VARCHAR(255) NOT NULL,
    `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updatedAt` DATETIME(3) NOT NULL,

    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `vehicles` (
    `id` CHAR(36) NOT NULL,
    `plate` VARCHAR(20) NOT NULL,
    `brand` VARCHAR(50) NOT NULL,
    `model` VARCHAR(50) NOT NULL,
    `year` INTEGER NOT NULL,
    `fuel` ENUM('GASOLINE', 'DIESEL', 'HYBRID', 'ELECTRIC') NOT NULL,
    `transmission` ENUM('MANUAL', 'AUTOMATIC') NOT NULL,
    `seats` INTEGER NOT NULL,
    `mileage` INTEGER NOT NULL,
    `status` ENUM('AVAILABLE', 'RENTED', 'MAINTENANCE', 'OUT_OF_SERVICE') NOT NULL DEFAULT 'AVAILABLE',
    `categoryId` CHAR(36) NOT NULL,
    `agencyId` CHAR(36) NOT NULL,
    `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updatedAt` DATETIME(3) NOT NULL,

    UNIQUE INDEX `vehicles_plate_key`(`plate`),
    INDEX `vehicles_status_idx`(`status`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `maintenances` (
    `id` CHAR(36) NOT NULL,
    `vehicleId` CHAR(36) NOT NULL,
    `type` VARCHAR(100) NOT NULL,
    `date` DATE NOT NULL,
    `cost` DECIMAL(10, 3) NOT NULL,
    `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `processed_events` (
    `eventId` CHAR(36) NOT NULL,
    `type` VARCHAR(50) NOT NULL,
    `processedAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

    PRIMARY KEY (`eventId`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- AddForeignKey
ALTER TABLE `vehicles` ADD CONSTRAINT `vehicles_categoryId_fkey` FOREIGN KEY (`categoryId`) REFERENCES `categories`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `vehicles` ADD CONSTRAINT `vehicles_agencyId_fkey` FOREIGN KEY (`agencyId`) REFERENCES `agencies`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `maintenances` ADD CONSTRAINT `maintenances_vehicleId_fkey` FOREIGN KEY (`vehicleId`) REFERENCES `vehicles`(`id`) ON DELETE CASCADE ON UPDATE CASCADE;
