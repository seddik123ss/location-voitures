using ContractService.Models;
using Microsoft.EntityFrameworkCore;

namespace ContractService.Data;

public class ContractDbContext : DbContext
{
    public ContractDbContext(DbContextOptions<ContractDbContext> options) : base(options)
    {
    }

    public DbSet<Contract> Contracts => Set<Contract>();
    public DbSet<Inspection> Inspections => Set<Inspection>();
    public DbSet<ExtraFee> ExtraFees => Set<ExtraFee>();

    protected override void OnModelCreating(ModelBuilder modelBuilder)
    {
        modelBuilder.Entity<Contract>(e =>
        {
            e.ToTable("contracts");
            e.HasKey(c => c.Id);
            e.Property(c => c.Number).HasMaxLength(30).IsRequired();
            e.HasIndex(c => c.Number).IsUnique();
            e.HasIndex(c => c.ReservationId);
            e.HasIndex(c => c.UserId);
            e.Property(c => c.Status).HasConversion<string>().HasMaxLength(10);

            e.HasMany(c => c.Inspections)
                .WithOne(i => i.Contract)
                .HasForeignKey(i => i.ContractId)
                .OnDelete(DeleteBehavior.Cascade);

            e.HasMany(c => c.ExtraFees)
                .WithOne(f => f.Contract)
                .HasForeignKey(f => f.ContractId)
                .OnDelete(DeleteBehavior.Cascade);
        });

        modelBuilder.Entity<Inspection>(e =>
        {
            e.ToTable("inspections");
            e.HasKey(i => i.Id);
            e.Property(i => i.Type).HasConversion<string>().HasMaxLength(10);
            e.Property(i => i.Damages).HasMaxLength(2000);
            // Un seul état des lieux de départ et un seul de retour par contrat
            e.HasIndex(i => new { i.ContractId, i.Type }).IsUnique();
        });

        modelBuilder.Entity<ExtraFee>(e =>
        {
            e.ToTable("extra_fees");
            e.HasKey(f => f.Id);
            e.Property(f => f.Type).HasConversion<string>().HasMaxLength(10);
            e.Property(f => f.Amount).HasPrecision(12, 3);
        });

        SeedData.Apply(modelBuilder);
    }
}
