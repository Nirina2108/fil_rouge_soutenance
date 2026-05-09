import { useEffect, useState, useRef } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import inscriptionService from '../services/inscriptionService';
import authService from '../services/authService';
import { obtenirImageFormation } from '../utils/formationImage';
import { formaterPrix, estGratuite } from '../utils/formaterPrix';
import Navbar from '../components/Navbar';
import Footer from '../components/Footer';
import Bouton from '../components/Bouton';
import './DashboardApprenantPage.css';

/*
 * Page dashboard de l'apprenant.
 *
 * Affiche la liste des formations auxquelles l'apprenant est inscrit avec
 * leur progression individuelle, des statistiques globales (nombre total,
 * progression moyenne, formations terminées), et un filtre par statut
 * (en cours / terminée). Permet aussi de changer la photo de profil.
 *
 * Route : /dashboard/apprenant (protégée par RouteApprenant dans App.jsx)
 */
export default function DashboardApprenantPage() {
    // Récupère l'utilisateur courant + setter pour MAJ après upload photo.
    const { utilisateur, setUtilisateur } = useAuth();
    const navigate = useNavigate();
    // Référence sur l'input file caché (déclenchée via le bouton avatar).
    const inputPhotoRef = useRef(null);

    // États : liste des inscriptions + drapeaux UI + filtre actif.
    const [inscriptions, setInscriptions] = useState([]);
    const [chargement, setChargement] = useState(true);
    const [messageOk, setMessageOk] = useState('');
    const [erreur, setErreur] = useState('');
    const [uploadingPhoto, setUploadingPhoto] = useState(false);
    // filtreActif : 'tout' | 'en_cours' | 'termine' | 'pas_commence'
    const [filtreActif, setFiltreActif] = useState('tout');

    // Charge les formations suivies par l'apprenant connecté.
    const chargerInscriptions = async () => {
        setChargement(true);
        try {
            const data = await inscriptionService.mesFormations();
            setInscriptions(data);
        } catch (error) {
            setErreur('Erreur lors du chargement des formations.');
        } finally {
            setChargement(false);
        }
    };

    // Effect : 1er chargement au montage.
    useEffect(() => {
        chargerInscriptions();
    }, []);

    // Upload de la photo de profil avec MAJ du contexte global.
    const handlePhotoChange = async (e) => {
        const fichier = e.target.files[0];
        if (!fichier) return;

        setUploadingPhoto(true);

        try {
            const data = await authService.uploadPhoto(fichier);

            // Synchro du contexte avec le user fraîchement uploadé.
            if (data.user) {
                setUtilisateur(data.user);
            } else {
                // Fallback : on relit depuis localStorage (mis à jour par authService).
                const utilisateurActuel = authService.obtenirUtilisateur();
                setUtilisateur(utilisateurActuel);
            }

            setMessageOk('Photo mise a jour.');
            setTimeout(() => setMessageOk(''), 3000);
        } catch {
            // catch sans variable car on ignore l'erreur pour afficher un message générique.
            setErreur("Erreur upload photo.");
        } finally {
            setUploadingPhoto(false);
        }
    };

    // Désinscription d'une formation avec confirmation native.
    const handleDesinscrire = async (formationId) => {
        if (!window.confirm('Se desinscrire de cette formation ?')) return;

        try {
            await inscriptionService.seDesinscrire(formationId);
            setMessageOk('Desinscription reussie.');
            chargerInscriptions();   // re-fetch pour MAJ la liste
            setTimeout(() => setMessageOk(''), 3000);
        } catch {
            setErreur('Erreur lors de la desinscription.');
        }
    };

    // Convertit un niveau en libellé (pattern lookup dans une map).
    // (param) => ({...}[param] || param) : retourne le libellé ou le param brut en fallback.
    const getNiveauLabel = (n) => ({
        debutant: 'Debutant',
        intermediaire: 'Intermediaire',
        avance: 'Avance'
    }[n] || n);

    // Filtrage des inscriptions selon le filtre actif.
    // Extrait en if/else en cascade pour éviter le ternaire imbriqué (Sonar S3358).
    const filtrerInscriptions = () => {
        if (filtreActif === 'tout') return inscriptions;
        if (filtreActif === 'en_cours') return inscriptions.filter(i => i.progression > 0 && i.progression < 100);
        if (filtreActif === 'termine') return inscriptions.filter(i => i.progression === 100);
        return inscriptions.filter(i => i.progression === 0);  // 'pas_commence'
    };
    const inscriptionsFiltrees = filtrerInscriptions();

    // Statistiques affichées dans les cards du haut.
    // .filter().length : compte le nombre d'éléments qui matchent.
    const totalTermines = inscriptions.filter(i => i.progression === 100).length;
    const totalEnCours = inscriptions.filter(i => i.progression > 0 && i.progression < 100).length;

    // Progression moyenne : somme des progressions / nombre total.
    // Ternaire pour éviter division par zéro si pas d'inscriptions.
    // .reduce((acc, val) => acc + val, 0) : pattern classique de somme.
    const moyenneProgression = inscriptions.length > 0
        ? Math.round(inscriptions.reduce((s, i) => s + i.progression, 0) / inscriptions.length)
        : 0;

    // Couleur du bandeau selon la progression — extraite pour éviter le ternaire imbriqué.
    const couleurBandeau = (progression) => {
        if (progression === 100) return '#4ade80';
        if (progression > 0) return '#f59e0b';
        return '#475569';
    };

    // Helper extrayant la branche conditionnelle (chargement / vide / liste des cards).
    // Évite le ternaire imbriqué que SonarLint flagge (javascript:S3358).
    const renderInscriptionsListe = () => {
        if (chargement) {
            return (
                <div className="da-chargement">
                    <div className="da-spinner" />
                    <p>Chargement de vos formations...</p>
                </div>
            );
        }
        if (inscriptionsFiltrees.length === 0) {
            return (
                <div className="da-vide">
                    <p>Aucune formation dans cette categorie.</p>
                    <Bouton variante="principal" onClick={() => navigate('/formations')}>
                        Decouvrir les formations
                    </Bouton>
                </div>
            );
        }
        return (
            <div className="da-grille">
                {inscriptionsFiltrees.map((inscription) => (
                    <div key={inscription.id} className="da-card">
                        {/* Image en rotation parmi les 7 photos bundlées (basée sur l'id de la formation). */}
                        <img
                            src={obtenirImageFormation(inscription.formation)}
                            alt={inscription.formation?.titre}
                            className="da-card-image"
                        />

                        <div className="da-card-body">
                            <div className="da-card-badges">
                                <span className="da-badge-niveau">
                                    {getNiveauLabel(inscription.formation?.niveau)}
                                </span>

                                {/* Badge prix de la formation suivie. */}
                                <span className={`da-badge-prix ${estGratuite(inscription.formation?.prix) ? 'da-badge-prix-gratuit' : ''}`}>
                                    {formaterPrix(inscription.formation?.prix)}
                                </span>

                                {inscription.progression === 100 && (
                                    <span className="da-badge-termine">Termine</span>
                                )}
                            </div>

                            <h3 className="da-card-titre">
                                {inscription.formation?.titre}
                            </h3>

                            <p className="da-card-description">
                                {inscription.formation?.description?.slice(0, 90)}
                                {inscription.formation?.description?.length > 90 ? '...' : ''}
                            </p>

                            <div className="da-progression-bloc">
                                <div className="da-progression-header">
                                    <span className="da-progression-label">Progression</span>
                                    <span className="da-progression-valeur">{inscription.progression}%</span>
                                </div>

                                <div className="da-progression-barre">
                                    <div
                                        className="da-progression-remplissage"
                                        style={{ width: `${inscription.progression}%` }}
                                    />
                                </div>
                            </div>
                        </div>

                        <div className="da-card-actions">
                            <Bouton
                                variante="principal"
                                taille="petit"
                                onClick={() => navigate(`/apprendre/${inscription.formation_id}`)}
                            >
                                {inscription.progression > 0 ? 'Continuer' : 'Commencer'}
                            </Bouton>

                            <Bouton
                                variante="danger"
                                taille="petit"
                                onClick={() => handleDesinscrire(inscription.formation_id)}
                            >
                                Ne plus suivre
                            </Bouton>
                        </div>
                    </div>
                ))}
            </div>
        );
    };

    return (
        <div className="da-page">
            <Navbar />

            <div className="da-hero">
                <div className="da-hero-contenu">
                    <span className="da-hero-label">ESPACE APPRENANT</span>
                    <h1 className="da-hero-titre">Dashboard Apprenant</h1>
                    <p className="da-hero-sous">
                        Bonjour <strong>{utilisateur?.nom}</strong> — suivez vos formations et votre progression
                    </p>

                    <div className="da-hero-tags">
                        <button
                            className="da-hero-tag"
                            onClick={() => document.querySelector('.da-grille')?.scrollIntoView({ behavior: 'smooth' })}
                        >
                            Formations inscrites
                        </button>

                        <button
                            className="da-hero-tag"
                            onClick={() => document.querySelector('.da-stats')?.scrollIntoView({ behavior: 'smooth' })}
                        >
                            Progression en temps reel
                        </button>

                        <button
                            className="da-hero-tag"
                            onClick={() => navigate('/formations')}
                        >
                            Modules interactifs
                        </button>

                        <button
                            className="da-hero-tag"
                            onClick={() => document.querySelector('.da-stats')?.scrollIntoView({ behavior: 'smooth' })}
                        >
                            Suivi personnalise
                        </button>
                    </div>
                </div>

                <div className="da-hero-droite">
                    <div className="da-avatar-wrapper">
                        {utilisateur?.photo_profil ? (
                            <img
                                src={`http://localhost:8001${utilisateur.photo_profil}`}
                                alt="profil"
                                className="da-avatar-img"
                            />
                        ) : (
                            <div className="da-avatar-initiales">
                                {utilisateur?.nom?.slice(0, 2).toUpperCase()}
                            </div>
                        )}

                        <button
                            className="da-avatar-btn"
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

            <div className="da-contenu">
                <div className="da-stats">
                    <div className="da-stat-card">
                        <span className="da-stat-valeur">{inscriptions.length}</span>
                        <span className="da-stat-label">Formations</span>
                    </div>

                    <div className="da-stat-card">
                        <span className="da-stat-valeur">{totalTermines}</span>
                        <span className="da-stat-label">Terminees</span>
                    </div>

                    <div className="da-stat-card">
                        <span className="da-stat-valeur">{totalEnCours}</span>
                        <span className="da-stat-label">En cours</span>
                    </div>

                    <div className="da-stat-card da-stat-progression">
                        <span className="da-stat-valeur">{moyenneProgression}%</span>
                        <span className="da-stat-label">Progression moyenne</span>

                        <div className="da-stat-barre">
                            <div
                                className="da-stat-barre-remplissage"
                                style={{ width: `${moyenneProgression}%` }}
                            />
                        </div>
                    </div>
                </div>

                {messageOk && <p className="da-succes">{messageOk}</p>}
                {erreur && <p className="da-erreur">{erreur}</p>}

                <div className="da-filtres">
                    {[
                        { key: 'tout', label: 'Toutes' },
                        { key: 'en_cours', label: 'En cours' },
                        { key: 'termine', label: 'Terminees' },
                        { key: 'debut', label: 'Non commencees' },
                    ].map((f) => (
                        <button
                            key={f.key}
                            className={`da-filtre-btn ${filtreActif === f.key ? 'da-filtre-actif' : ''}`}
                            onClick={() => setFiltreActif(f.key)}
                        >
                            {f.label}
                        </button>
                    ))}

                    <span className="da-filtres-compteur">
                        {inscriptionsFiltrees.length} formation{inscriptionsFiltrees.length > 1 ? 's' : ''}
                    </span>

                    <div style={{ marginLeft: 'auto' }}>
                        <Bouton
                            variante="principal"
                            taille="petit"
                            onClick={() => navigate('/formations')}
                        >
                            + Decouvrir des formations
                        </Bouton>
                    </div>
                </div>

                {renderInscriptionsListe()}
            </div>

            <Footer />
        </div>
    );
}