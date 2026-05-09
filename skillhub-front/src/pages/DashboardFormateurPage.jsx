import { useEffect, useState, useRef } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import formationService from '../services/formationService';
import authService from '../services/authService';
import { obtenirImageFormation } from '../utils/formationImage';
import { formaterPrix, estGratuite } from '../utils/formaterPrix';
import Navbar from '../components/Navbar';
import Footer from '../components/Footer';
import Bouton from '../components/Bouton';
import ModalFormation from '../components/ModalFormation';
import ModalModules from '../components/ModalModules';
import './DashboardFormateurPage.css';

/*
 * Page dashboard du formateur.
 *
 * Affiche les formations créées par le formateur avec leur statistiques
 * (vues, apprenants inscrits), un indicateur de présence du PDF, et permet
 * de :
 *  - Créer une nouvelle formation (avec PDF optionnel) via ModalFormation
 *  - Modifier une formation existante (titre, description, PDF)
 *  - Gérer les modules (leçons) via ModalModules
 *  - Télécharger le PDF du cours
 *  - Supprimer une formation
 *  - Mettre à jour sa photo de profil
 *
 * Route : /dashboard/formateur (protégée par RouteFormateur dans App.jsx)
 */
export default function DashboardFormateurPage() {
    const { utilisateur, setUtilisateur } = useAuth();
    const navigate = useNavigate();
    // Référence sur l'input file photo caché.
    const inputPhotoRef = useRef(null);

    // Liste des formations + drapeaux UI.
    const [formations, setFormations] = useState([]);
    const [chargement, setChargement] = useState(true);
    // Ouverture des deux modals (formation et modules).
    const [modalFormationOuverte, setModalFormationOuverte] = useState(false);
    const [modalModulesOuverte, setModalModulesOuverte] = useState(false);
    // Formation passée à ModalFormation (null = mode création, objet = mode modification).
    const [formationModif, setFormationModif] = useState(null);
    // Formation passée à ModalModules (pour gérer ses modules).
    const [formationModules, setFormationModules] = useState(null);
    const [messageOk, setMessageOk] = useState('');
    const [erreur, setErreur] = useState('');
    const [uploadingPhoto, setUploadingPhoto] = useState(false);
    // Filtre niveau : 'tout' | 'debutant' | 'intermediaire' | 'avance'
    const [filtreActif, setFiltreActif] = useState('tout');

    // Charge les formations créées par le formateur connecté.
    const chargerFormations = async () => {
        setChargement(true);
        try {
            const data = await formationService.recupererMesFormations();
            setFormations(data);
        } catch (error) {
            setErreur('Erreur lors du chargement.');
        } finally {
            setChargement(false);
        }
    };

    // 1er chargement au montage.
    useEffect(() => {
        chargerFormations();
    }, []);

    // Upload photo de profil (identique au DashboardApprenantPage).
    const handlePhotoChange = async (e) => {
        const fichier = e.target.files[0];
        if (!fichier) return;

        setUploadingPhoto(true);

        try {
            const data = await authService.uploadPhoto(fichier);

            // Synchro contexte avec le user fraîchement uploadé.
            if (data.user) {
                setUtilisateur(data.user);
            } else {
                const utilisateurActuel = authService.obtenirUtilisateur();
                setUtilisateur(utilisateurActuel);
            }

            setMessageOk('Photo mise a jour.');
            setTimeout(() => setMessageOk(''), 3000);
        } catch {
            setErreur("Erreur upload photo.");
        } finally {
            setUploadingPhoto(false);
        }
    };

    // Suppression d'une formation avec confirmation native.
    const handleSupprimer = async (id) => {
        if (!window.confirm('Supprimer cette formation ?')) return;

        try {
            await formationService.supprimerFormation(id);
            setMessageOk('Formation supprimee.');
            chargerFormations();   // re-fetch pour MAJ la liste
            setTimeout(() => setMessageOk(''), 3000);
        } catch {
            setErreur('Erreur suppression.');
        }
    };

    // Callback appelé par ModalFormation après sauvegarde réussie.
    // Affiche un message dynamique (création vs modification) puis rafraîchit la liste.
    const handleSauvegarder = () => {
        setMessageOk(formationModif ? 'Formation modifiee.' : 'Formation creee.');
        setModalFormationOuverte(false);
        setFormationModif(null);     // reset le mode (revient en création la prochaine fois)
        chargerFormations();
        setTimeout(() => setMessageOk(''), 3000);
    };

    // Téléchargement du PDF d'une formation depuis sa card.
    const handleTelechargerPdf = async (formation) => {
        try {
            await formationService.telechargerPdf(formation.id, formation.titre);
        } catch (error) {
            const msg = error.response?.data?.message || 'Erreur lors du téléchargement.';
            setErreur(msg);
            setTimeout(() => setErreur(''), 3000);
        }
    };

    // Convertit le niveau en libellé. Pattern lookup dans une map avec fallback.
    const getNiveauLabel = (n) => ({
        debutant: 'Debutant',
        intermediaire: 'Intermediaire',
        avance: 'Avance'
    }[n] || n);

    // Filtrage par niveau (ternaire simple : "tout" = pas de filtre).
    const formationsFiltrees = filtreActif === 'tout'
        ? formations
        : formations.filter(f => f.niveau === filtreActif);

    // Stats agrégées : somme totale vues et inscrits sur l'ensemble des formations.
    // .reduce((acc, val) => acc + ..., 0) : pattern de somme.
    // (val.field || 0) : sécurité si la propriété est absente.
    const totalVues = formations.reduce((s, f) => s + (f.nombre_de_vues || 0), 0);
    const totalApprenants = formations.reduce((s, f) => s + (f.inscriptions_count || 0), 0);

    // Helper extrayant la branche conditionnelle (chargement / vide / liste des cards).
    // Évite le ternaire imbriqué que SonarLint flagge (javascript:S3358).
    const renderFormationsListe = () => {
        if (chargement) {
            return (
                <div className="df-chargement">
                    <div className="df-spinner" />
                    <p>Chargement de vos formations...</p>
                </div>
            );
        }
        if (formationsFiltrees.length === 0) {
            return (
                <div className="df-vide">
                    <p>Aucune formation dans cette categorie.</p>
                    <Bouton variante="principal" onClick={() => setModalFormationOuverte(true)}>
                        Creer une formation
                    </Bouton>
                </div>
            );
        }
        return (
            <div className="df-grille">
                {formationsFiltrees.map((formation) => (
                    <div key={formation.id} className="df-card">
                        {/* Image en rotation parmi les 7 photos bundlées (cf. utils/formationImage). */}
                        <img
                            src={obtenirImageFormation(formation)}
                            alt={formation.titre}
                            className="df-card-image"
                        />

                        <div className="df-card-body">
                            <div className="df-card-badges">
                                <span className="df-badge-niveau">{getNiveauLabel(formation.niveau)}</span>
                                <span className="df-badge-categorie">{formation.categorie?.replace('_', ' ')}</span>
                                {/* Badge prix : visible aussi côté formateur pour qu'il voie ses tarifs. */}
                                <span className={`df-badge-prix ${estGratuite(formation.prix) ? 'df-badge-prix-gratuit' : ''}`}>
                                    {formaterPrix(formation.prix)}
                                </span>
                            </div>

                            <h3 className="df-card-titre">{formation.titre}</h3>

                            <p className="df-card-description">
                                {formation.description?.slice(0, 90)}
                                {formation.description?.length > 90 ? '...' : ''}
                            </p>

                            <div className="df-card-pdf">
                                {formation.fichier_pdf ? (
                                    <span className="df-pdf-ok">📄 Cours PDF disponible</span>
                                ) : (
                                    <span className="df-pdf-manquant">⚠ Aucun PDF uploadé</span>
                                )}
                            </div>

                            <div className="df-card-stats">
                                <div className="df-stat-mini">
                                    <span className="df-stat-mini-val">{formation.nombre_de_vues}</span>
                                    <span className="df-stat-mini-label">vues</span>
                                </div>

                                <div className="df-stat-mini">
                                    <span className="df-stat-mini-val">{formation.inscriptions_count}</span>
                                    <span className="df-stat-mini-label">apprenants</span>
                                </div>
                            </div>
                        </div>

                        <div className="df-card-actions">
                            <div className="df-card-actions-ligne">
                                <Bouton
                                    variante="fantome"
                                    taille="petit"
                                    onClick={() => navigate(`/formation/${formation.id}`)}
                                >
                                    Voir
                                </Bouton>

                                <Bouton
                                    variante="secondaire"
                                    taille="petit"
                                    onClick={() => {
                                        setFormationModif(formation);
                                        setModalFormationOuverte(true);
                                    }}
                                >
                                    Modifier
                                </Bouton>

                                <Bouton
                                    variante="secondaire"
                                    taille="petit"
                                    onClick={() => {
                                        setFormationModules(formation);
                                        setModalModulesOuverte(true);
                                    }}
                                >
                                    Modules
                                </Bouton>

                                {formation.fichier_pdf && (
                                    <Bouton
                                        variante="secondaire"
                                        taille="petit"
                                        onClick={() => handleTelechargerPdf(formation)}
                                    >
                                        PDF
                                    </Bouton>
                                )}
                            </div>

                            <div className="df-card-actions-supprimer">
                                <Bouton
                                    variante="danger"
                                    taille="petit"
                                    onClick={() => handleSupprimer(formation.id)}
                                >
                                    Supprimer
                                </Bouton>
                            </div>
                        </div>
                    </div>
                ))}
            </div>
        );
    };

    return (
        <div className="df-page">
            <Navbar />

            <div className="df-hero">
                <div className="df-hero-contenu">
                    <span className="df-hero-label">ESPACE FORMATEUR</span>
                    <h1 className="df-hero-titre">Dashboard Formateur</h1>
                    <p className="df-hero-sous">
                        Bienvenue <strong>{utilisateur?.nom}</strong> — gerez vos formations, modules et apprenants
                    </p>

                    <div className="df-hero-tags">
                        <button
                            className="df-hero-tag"
                            onClick={() => {
                                setFormationModif(null);
                                setModalFormationOuverte(true);
                            }}
                        >
                            Creer des formations
                        </button>

                        <button
                            className="df-hero-tag"
                            onClick={() => document.querySelector('.df-grille')?.scrollIntoView({ behavior: 'smooth' })}
                        >
                            Gerer les modules
                        </button>

                        <button
                            className="df-hero-tag"
                            onClick={() => document.querySelector('.df-stats')?.scrollIntoView({ behavior: 'smooth' })}
                        >
                            Suivre les apprenants
                        </button>

                        <button
                            className="df-hero-tag"
                            onClick={() => document.querySelector('.df-stats')?.scrollIntoView({ behavior: 'smooth' })}
                        >
                            Statistiques en direct
                        </button>
                    </div>
                </div>

                <div className="df-hero-droite">
                    <div className="df-avatar-wrapper">
                        {utilisateur?.photo_profil ? (
                            <img
                                src={`http://localhost:8001${utilisateur.photo_profil}`}
                                alt="profil"
                                className="df-avatar-img"
                            />
                        ) : (
                            <div className="df-avatar-initiales">
                                {utilisateur?.nom?.slice(0, 2).toUpperCase()}
                            </div>
                        )}

                        <button
                            className="df-avatar-btn"
                            onClick={() => inputPhotoRef.current.click()}
                            title="Changer la photo"
                            disabled={uploadingPhoto}
                        >
                            {uploadingPhoto ? '...' : '📷'}
                        </button>

                        <input
                            type="file"
                            ref={inputPhotoRef}
                            accept="image/jpeg,image/png,image/gif"
                            onChange={handlePhotoChange}
                            style={{ display: 'none' }}
                        />
                    </div>
                </div>
            </div>

            <div className="df-contenu">
                <div className="df-stats">
                    <div className="df-stat-card">
                        <span className="df-stat-valeur">{formations.length}</span>
                        <span className="df-stat-label">Formations</span>
                    </div>

                    <div className="df-stat-card">
                        <span className="df-stat-valeur">{totalApprenants}</span>
                        <span className="df-stat-label">Apprenants</span>
                    </div>

                    <div className="df-stat-card">
                        <span className="df-stat-valeur">{totalVues}</span>
                        <span className="df-stat-label">Vues totales</span>
                    </div>

                    <div
                        className="df-stat-card df-stat-action"
                        onClick={() => {
                            setFormationModif(null);
                            setModalFormationOuverte(true);
                        }}
                    >
                        <span className="df-stat-plus">+</span>
                        <span className="df-stat-label">Nouvelle formation</span>
                    </div>
                </div>

                {messageOk && <p className="df-succes">{messageOk}</p>}
                {erreur && <p className="df-erreur">{erreur}</p>}

                <div className="df-filtres">
                    {['tout', 'debutant', 'intermediaire', 'avance'].map((f) => (
                        <button
                            key={f}
                            className={`df-filtre-btn ${filtreActif === f ? 'df-filtre-actif' : ''}`}
                            onClick={() => setFiltreActif(f)}
                        >
                            {f === 'tout' ? 'Toutes' : getNiveauLabel(f)}
                        </button>
                    ))}

                    <span className="df-filtres-compteur">
                        {formationsFiltrees.length} formation{formationsFiltrees.length > 1 ? 's' : ''}
                    </span>
                </div>

                {renderFormationsListe()}
            </div>

            <Footer />

            {modalFormationOuverte && (
                <ModalFormation
                    formation={formationModif}
                    onFermer={() => {
                        setModalFormationOuverte(false);
                        setFormationModif(null);
                    }}
                    onSauvegarder={handleSauvegarder}
                />
            )}

            {modalModulesOuverte && (
                <ModalModules
                    formation={formationModules}
                    onFermer={() => {
                        setModalModulesOuverte(false);
                        setFormationModules(null);
                    }}
                />
            )}
        </div>
    );
}