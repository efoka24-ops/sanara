/** Interface remplaçable du fournisseur d'IA (constitution VIII). Implémentée en US21. */
export interface AssistantProvider {
  readPrescription(image: Uint8Array): Promise<unknown>;
}
