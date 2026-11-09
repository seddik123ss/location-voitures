import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  serverExternalPackages: ["eureka-js-client", "amqplib"],
};

export default nextConfig;