import { describe, expect, it, vi } from "vitest";

const calls = vi.hoisted(() => ({ configs: [] as any[], started: 0 }));

vi.mock("eureka-js-client", () => ({
  Eureka: class {
    constructor(cfg: any) {
      calls.configs.push(cfg);
    }
    start(cb?: (err?: Error) => void) {
      calls.started++;
      cb?.();
    }
    stop(cb?: () => void) {
      cb?.();
    }
  },
}));

import { startEureka } from "../lib/eureka";

describe("Inscription Eureka", () => {
  it("s'inscrit sous RENTAL-VEHICLE avec host.docker.internal:8102 et /health", () => {
    startEureka();
    const { instance, eureka } = calls.configs[0];

    expect(calls.started).toBe(1);
    expect(instance.app).toBe("RENTAL-VEHICLE");
    expect(instance.hostName).toBe("host.docker.internal");
    expect(instance.ipAddr).toBe("host.docker.internal");
    expect(instance.port.$).toBe(8102);
    expect(instance.healthCheckUrl).toBe("http://host.docker.internal:8102/health");
    expect(eureka.host).toBe("localhost");
    expect(eureka.port).toBe(8761);
    expect(eureka.servicePath).toBe("/eureka/apps/");
  });

  it("ne s'inscrit qu'une seule fois", () => {
    startEureka();
    expect(calls.configs).toHaveLength(1);
  });
});