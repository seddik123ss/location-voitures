using System;
using System.Collections.Generic;
using Microsoft.EntityFrameworkCore.Migrations;

#nullable disable

#pragma warning disable CA1814 // Prefer jagged arrays over multidimensional

namespace ContractService.Data.Migrations
{
    /// <inheritdoc />
    public partial class InitialCreate : Migration
    {
        /// <inheritdoc />
        protected override void Up(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.CreateTable(
                name: "contracts",
                columns: table => new
                {
                    Id = table.Column<Guid>(type: "uuid", nullable: false),
                    Number = table.Column<string>(type: "character varying(30)", maxLength: 30, nullable: false),
                    ReservationId = table.Column<Guid>(type: "uuid", nullable: false),
                    UserId = table.Column<Guid>(type: "uuid", nullable: false),
                    VehicleId = table.Column<Guid>(type: "uuid", nullable: false),
                    SignedAt = table.Column<DateTime>(type: "timestamp with time zone", nullable: false),
                    Status = table.Column<string>(type: "character varying(10)", maxLength: 10, nullable: false),
                    ClosedAt = table.Column<DateTime>(type: "timestamp with time zone", nullable: true)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_contracts", x => x.Id);
                });

            migrationBuilder.CreateTable(
                name: "extra_fees",
                columns: table => new
                {
                    Id = table.Column<Guid>(type: "uuid", nullable: false),
                    ContractId = table.Column<Guid>(type: "uuid", nullable: false),
                    Type = table.Column<string>(type: "character varying(10)", maxLength: 10, nullable: false),
                    Amount = table.Column<decimal>(type: "numeric(12,3)", precision: 12, scale: 3, nullable: false)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_extra_fees", x => x.Id);
                    table.ForeignKey(
                        name: "FK_extra_fees_contracts_ContractId",
                        column: x => x.ContractId,
                        principalTable: "contracts",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Cascade);
                });

            migrationBuilder.CreateTable(
                name: "inspections",
                columns: table => new
                {
                    Id = table.Column<Guid>(type: "uuid", nullable: false),
                    ContractId = table.Column<Guid>(type: "uuid", nullable: false),
                    Type = table.Column<string>(type: "character varying(10)", maxLength: 10, nullable: false),
                    Mileage = table.Column<int>(type: "integer", nullable: false),
                    FuelLevel = table.Column<double>(type: "double precision", nullable: false),
                    Damages = table.Column<string>(type: "character varying(2000)", maxLength: 2000, nullable: true),
                    Photos = table.Column<List<string>>(type: "text[]", nullable: false),
                    InspectedAt = table.Column<DateTime>(type: "timestamp with time zone", nullable: false)
                },
                constraints: table =>
                {
                    table.PrimaryKey("PK_inspections", x => x.Id);
                    table.ForeignKey(
                        name: "FK_inspections_contracts_ContractId",
                        column: x => x.ContractId,
                        principalTable: "contracts",
                        principalColumn: "Id",
                        onDelete: ReferentialAction.Cascade);
                });

            migrationBuilder.InsertData(
                table: "contracts",
                columns: new[] { "Id", "ClosedAt", "Number", "ReservationId", "SignedAt", "Status", "UserId", "VehicleId" },
                values: new object[] { new Guid("6804f1c2-925a-4604-94d4-487853bc1cac"), new DateTime(2026, 10, 5, 18, 45, 0, 0, DateTimeKind.Utc), "CTR-2026-0001", new Guid("30eeff32-a413-42fb-9845-8ede4b7f98e0"), new DateTime(2026, 10, 1, 9, 0, 0, 0, DateTimeKind.Utc), "CLOSED", new Guid("611f32de-ce7b-4927-b1c4-d113b6479d59"), new Guid("a1154300-bea1-4072-b750-2b00cf1f0bcd") });

            migrationBuilder.InsertData(
                table: "extra_fees",
                columns: new[] { "Id", "Amount", "ContractId", "Type" },
                values: new object[,]
                {
                    { new Guid("9d2a4e3c-5b6f-4a0c-9e43-5f6a7b8c9da2"), 160.000m, new Guid("6804f1c2-925a-4604-94d4-487853bc1cac"), "LATE" },
                    { new Guid("ae3b5f4d-4c7a-4b1d-8f54-6a7b8c9daeb3"), 35.000m, new Guid("6804f1c2-925a-4604-94d4-487853bc1cac"), "FUEL" }
                });

            migrationBuilder.InsertData(
                table: "inspections",
                columns: new[] { "Id", "ContractId", "Damages", "FuelLevel", "InspectedAt", "Mileage", "Photos", "Type" },
                values: new object[,]
                {
                    { new Guid("5b0e2c1a-7f3d-4e8a-9c21-3d4f5a6b7c80"), new Guid("6804f1c2-925a-4604-94d4-487853bc1cac"), null, 1.0, new DateTime(2026, 10, 1, 9, 0, 0, 0, DateTimeKind.Utc), 42500, new List<string>(), "PICKUP" },
                    { new Guid("8c1f3d2b-6a4e-4f9b-8d32-4e5a6b7c8d91"), new Guid("6804f1c2-925a-4604-94d4-487853bc1cac"), null, 0.75, new DateTime(2026, 10, 5, 18, 30, 0, 0, DateTimeKind.Utc), 42980, new List<string>(), "RETURN" }
                });

            migrationBuilder.CreateIndex(
                name: "IX_contracts_Number",
                table: "contracts",
                column: "Number",
                unique: true);

            migrationBuilder.CreateIndex(
                name: "IX_contracts_ReservationId",
                table: "contracts",
                column: "ReservationId");

            migrationBuilder.CreateIndex(
                name: "IX_contracts_UserId",
                table: "contracts",
                column: "UserId");

            migrationBuilder.CreateIndex(
                name: "IX_extra_fees_ContractId",
                table: "extra_fees",
                column: "ContractId");

            migrationBuilder.CreateIndex(
                name: "IX_inspections_ContractId_Type",
                table: "inspections",
                columns: new[] { "ContractId", "Type" },
                unique: true);
        }

        /// <inheritdoc />
        protected override void Down(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.DropTable(
                name: "extra_fees");

            migrationBuilder.DropTable(
                name: "inspections");

            migrationBuilder.DropTable(
                name: "contracts");
        }
    }
}
