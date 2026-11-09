import { defineConfig, loadEnv } from "vite";

export default defineConfig({
  test: {
    env: loadEnv("", process.cwd(), ""), // charge le .env
    include: ["tests/**/*.test.ts"],
    fileParallelism: false, // les tests partagent la même base
    testTimeout: 15000,
  },
});