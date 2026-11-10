export interface CurrentUser {
  id: number;
  name: string;
  email: string;
  is_admin: boolean;
}

export interface AbonneInfo {
  found: boolean;
  reference: string;
  ref_prec: string | null;
  departement: number;
  agence: number;
  centre: number;
  police: string | null;
  etat_abo: string | null;
  etat_comp: string | null;
  nom_abonne: string | null;
  ville: string | null;
  cip: string | null;
  telephone: string | null;
  email: string | null;
  datesais: string | null;
  nomop: string | null;
  already_submitted?: boolean;
  submission_status?: 'en_attente' | 'valide' | 'rejete' | null;
  motif_rejet?: string | null;
  has_staging_pending: boolean;
  staging_pending?: {
    cip: string;
    telephone: string;
    email: string | null;
    datesais: string;
    nomop: string;
  };
}

export interface StagingItem {
  id: number;
  reference: string;
  cip: string;
  telephone: string;
  email: string | null;
  cip_document_path?: string | null;
  cip_document_data?: string | null;
  datesais: string;
  nomop: string;
  statut: 'en_attente' | 'valide' | 'rejete';
  motif_rejet?: string | null;
  created_at: string;
  user?: { name: string; email: string };
}
