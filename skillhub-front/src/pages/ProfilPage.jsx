// useState : état réactif | useRef : référence vers l'input file caché.
import { useState, useRef } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import authService from '../services/authService';
import Navbar from '../components/Navbar';
import Footer from '../components/Footer';
import Bouton from '../components/Bouton';
import './ProfilPage.css';

/*
 * Page profil utilisateur (commune apprenant et formateur).
 *
 * Permet à l'utilisateur connecté de :
 *  - Voir ses informations (nom, email, rôle).
 *  - Changer sa photo de profil (upload jpg/png/gif, max 2 MB côté backend).
 *
 * Route : /profil (accessible à tout utilisateur connecté, peu importe le rôle)
 */
export default function ProfilPage() {
    // Utilisateur courant + setter pour MAJ après upload photo.
    const { utilisateur, setUtilisateur } = useAuth();
    const navigate = useNavigate();

    // Référence vers l'input file caché (pour le déclencher via un bouton stylisé).
    const inputRef = useRef(null);

    // État local : preview avant upload + fichier sélectionné + flags + messages.
    const [apercu, setApercu] = useState(null);          // URL Blob temporaire pour l'aperçu
    const [fichier, setFichier] = useState(null);        // objet File à uploader
    const [chargement, setChargement] = useState(false); // pendant l'upload
    const [messageOk, setMessageOk] = useState('');
    const [erreur, setErreur] = useState('');

    // Quand l'utilisateur sélectionne un fichier dans le dialogue OS.
    const handleFichierChange = (e) => {
        const f = e.target.files[0];     // 1er fichier (sélection unique)
        if (!f) return;                  // annulation du dialogue
        setFichier(f);
        // URL.createObjectURL crée une URL temporaire vers le blob en mémoire.
        // Affichable dans <img src=...> sans uploader au serveur.
        setApercu(URL.createObjectURL(f));
    };

    // Upload réel vers le backend.
    const handleUpload = async () => {
        if (!fichier) return;            // sécurité
        setChargement(true);
        setErreur('');
        setMessageOk('');

        try {
            // authService.uploadPhoto envoie en multipart vers /api/profil/photo.
            const data = await authService.uploadPhoto(fichier);
            setMessageOk('Photo mise a jour avec succes.');
            setFichier(null);            // reset l'état d'upload

            // MAJ du contexte global avec le nouvel utilisateur (qui a la nouvelle photo).
            if (data.user) {
                setUtilisateur(data.user);
            } else {
                // Fallback : on relit depuis localStorage (mis à jour par authService).
                const utilisateurActuel = authService.obtenirUtilisateur();
                setUtilisateur(utilisateurActuel);
            }
        } catch (error) {
            setErreur("Erreur lors de l'upload. Verifiez le format (jpg, png) et la taille (max 2MB).");
        } finally {
            setChargement(false);
        }
    };

    // Calcule l'URL de la photo à afficher : aperçu local en priorité (si en cours
    // de sélection), sinon photo serveur, sinon null pour afficher les initiales.
    const photoActuelle = apercu || utilisateur?.photo_profil
        ? (apercu || `http://localhost:8001${utilisateur?.photo_profil}`)
        : null;

    return (
        <div className="profil-page">
            <Navbar />

            <div className="profil-contenu">
                <h1 className="profil-titre">Mon profil</h1>

                {/* Messages succès / erreur conditionnels */}
                {messageOk && <p className="profil-succes">{messageOk}</p>}
                {erreur && <p className="profil-erreur">{erreur}</p>}

                <div className="profil-carte">
                    {/* Section photo : avatar + bouton changer + bouton upload (si fichier sélectionné) */}
                    <div className="profil-photo-section">
                        <div className="profil-avatar-wrapper">
                            {/* Si une photo est dispo : <img>, sinon : initiales */}
                            {photoActuelle ? (
                                <img
                                    src={photoActuelle}
                                    alt="Photo de profil"
                                    className="profil-avatar-img"
                                />
                            ) : (
                                <div className="profil-avatar-initiales">
                                    {/* 2 premières lettres du nom en majuscule */}
                                    {utilisateur?.nom?.slice(0, 2).toUpperCase()}
                                </div>
                            )}

                            {/* Bouton appareil photo : clic = ouvrir le dialogue file (via la ref) */}
                            <button
                                className="profil-avatar-btn"
                                onClick={() => inputRef.current.click()}
                                title="Changer la photo"
                            >
                                📷
                            </button>
                        </div>

                        {/* Input file caché (style display:none) — accessed via ref */}
                        <input
                            type="file"
                            ref={inputRef}
                            accept="image/jpeg,image/png,image/gif"  // limite à images
                            onChange={handleFichierChange}
                            style={{ display: 'none' }}
                        />

                        <p className="profil-photo-hint">
                            JPG, PNG ou GIF — max 2 MB
                        </p>

                        {/* Bouton "Sauvegarder" affiché seulement si un fichier est sélectionné */}
                        {fichier && (
                            <Bouton
                                variante="principal"
                                taille="moyen"
                                onClick={handleUpload}
                                disabled={chargement}
                            >
                                {chargement ? 'Upload...' : 'Sauvegarder la photo'}
                            </Bouton>
                        )}
                    </div>

                    {/* Section infos : nom, email, badge rôle */}
                    <div className="profil-infos">
                        <div className="profil-info-ligne">
                            <span className="profil-info-label">Nom</span>
                            <span className="profil-info-valeur">{utilisateur?.nom}</span>
                        </div>

                        <div className="profil-info-ligne">
                            <span className="profil-info-label">Email</span>
                            <span className="profil-info-valeur">{utilisateur?.email}</span>
                        </div>

                        <div className="profil-info-ligne">
                            <span className="profil-info-label">Role</span>
                            <span className="profil-badge-role">
                                {/* Affichage capitalisé selon le rôle */}
                                {utilisateur?.role === 'formateur' ? 'Formateur' : 'Apprenant'}
                            </span>
                        </div>
                    </div>
                </div>

                {/* Bouton retour : navigue vers le dashboard adapté au rôle */}
                <div className="profil-retour">
                    <Bouton
                        variante="secondaire"
                        onClick={() =>
                            navigate(
                                utilisateur?.role === 'formateur'
                                    ? '/dashboard/formateur'
                                    : '/dashboard/apprenant'
                            )
                        }
                    >
                        Retour au dashboard
                    </Bouton>
                </div>
            </div>

            <Footer />
        </div>
    );
}
