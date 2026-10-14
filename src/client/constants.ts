export const permissions = {
  read: ["file.read", "file.read-content"],
  write: ["file.read", "file.read-content", "file.create", "file.update"],
};
export const packageFiles = ["server_packages.sii", "server_packages.dat"] as const;
export const operationTimeout = 300000;
export const presenceStaleTime = 15000;
export const refreshInterval = 30000;
