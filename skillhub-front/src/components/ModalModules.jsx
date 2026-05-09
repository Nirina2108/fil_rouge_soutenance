// Hooks React : useState (état réactif), useEffect (effet de bord type "componentDidMount").
import { useEffect, useState } from 'react';
// Service métier qui parle à l'API Laravel (CRUD modules).
import moduleService from '../services/moduleService';
import Bouton from './Bouton';
import './ModalModules.css';

/**
 * Modal de gestion des modules (leçons) d'une formation.
 *
 * Affiche la liste des modules existants triés par ordre, et un formulaire
 * pour en créer / modifier / supprimer. Réservé au formateur propriétaire
 * de la formation (filtrage côté backend via le contrôleur Module).
 *
 * @param {object} props
 * @param {object} props.formation Formation parente dont on gère les modules
 * @param {function} props.onFermer Ferme la modal
 */
export default function ModalModules({ formation, onFermer }) {
    // Liste des modules récupérée du backend.
    const [modules,      setModules]      = useState([]);
    const [chargement,   setChargement]   = useState(true);     // true tant que la 1re requête n'a pas répondu
    const [erreur,       setErreur]       = useState('');
    const [messageOk,    setMessageOk]    = useState('');

    // États du formulaire d'ajout de nouveau module.
    const [titre,        setTitre]        = useState('');
    const [contenu,      setContenu]      = useState('');
    const [ordre,        setOrdre]        = useState(1);         // ordre auto-rempli avec next available
    const [ajoutVisible, setAjoutVisible] = useState(false);     // toggle visibilité du formulaire
    const [loadingAjout, setLoadingAjout] = useState(false);     // bloque double-clic

    // Module en cours de modification (null si aucun en cours).
    // C'est un OBJET cloné qu'on édite, pour ne pas modifier l'original tant qu'on ne sauvegarde pas.
    const [moduleModif,  setModuleModif]  = useState(null);

    // Charge la liste des modules depuis l'API et pré-remplit l'ordre suggéré.
    const chargerModules = async () => {
        setChargement(true);
        try {
            const data = await moduleService.recupererModules(formation.id);
            setModules(data);
            // Suggestion d'ordre : prochain numéro disponible (length + 1).
            setOrdre(data.length + 1);
        } catch (error) {
            setErreur('Erreur lors du chargement des modules.');
        } finally {
            setChargement(false);
        }
    };

    // useEffect avec [formation.id] : se déclenche au mount et si la formation change.
    useEffect(() => {
        chargerModules();
    }, [formation.id]);

    // Ferme la modal au clic sur l'overlay (UX standard).
    const handleOverlayClick = (e) => {
        if (e.target === e.currentTarget) onFermer();
    };

    // Soumission du formulaire d'ajout : crée le module puis recharge la liste.
    const handleAjouter = async (e) => {
        e.preventDefault();
        setErreur('');
        setLoadingAjout(true);

        try {
            await moduleService.creerModule(formation.id, { titre, contenu, ordre });
            // Reset des champs après succès.
            setTitre('');
            setContenu('');
            setAjoutVisible(false);
            setMessageOk('Module ajouté avec succès.');
            chargerModules();    // re-fetch pour avoir la liste à jour
            // Auto-clear du message après 3s.
            setTimeout(() => setMessageOk(''), 3000);
        } catch (error) {
            setErreur('Erreur lors de la création du module.');
        } finally {
            setLoadingAjout(false);
        }
    };

    // Soumission de la modification d'un module existant.
    const handleModifier = async (e) => {
        e.preventDefault();
        setErreur('');

        try {
            await moduleService.modifierModule(moduleModif.id, {
                titre:   moduleModif.titre,
                contenu: moduleModif.contenu,
                ordre:   moduleModif.ordre,
            });
            setModuleModif(null);    // sort du mode édition
            setMessageOk('Module modifié avec succès.');
            chargerModules();
            setTimeout(() => setMessageOk(''), 3000);
        } catch (error) {
            setErreur('Erreur lors de la modification du module.');
        }
    };

    // Suppression d'un module avec confirmation native du navigateur.
    const handleSupprimer = async (id) => {
        // window.confirm : popup OK/Annuler synchrone du navigateur.
        const confirme = window.confirm('Supprimer ce module ?');
        if (!confirme) return;       // annulation : on sort

        try {
            await moduleService.supprimerModule(id);
            setMessageOk('Module supprimé.');
            chargerModules();
            setTimeout(() => setMessageOk(''), 3000);
        } catch (error) {
            setErreur('Erreur lors de la suppression.');
        }
    };

    // Rend une card module selon le mode (édition ou affichage simple).
    // Helper extrait pour éviter le ternaire imbriqué SonarLint javascript:S3358.
    const renderModuleCard = (module) => {
        if (moduleModif?.id === module.id) {
            return (
                <form onSubmit={handleModifier} className="mm-form-modif">
                    <input
                        type="text"
                        value={moduleModif.titre}
                        onChange={(e) => setModuleModif({ ...moduleModif, titre: e.target.value })}
                        className="mm-input"
                        required
                    />
                    <textarea
                        value={moduleModif.contenu}
                        onChange={(e) => setModuleModif({ ...moduleModif, contenu: e.target.value })}
                        className="mm-textarea"
                        rows={3}
                        required
                    />
                    <input
                        type="number"
                        value={moduleModif.ordre}
                        onChange={(e) => setModuleModif({ ...moduleModif, ordre: parseInt(e.target.value) })}
                        className="mm-input-ordre"
                        min={1}
                        required
                    />
                    <div className="mm-item-actions">
                        <Bouton type="submit" variante="principal" taille="petit">
                            Sauvegarder
                        </Bouton>
                        <Bouton variante="secondaire" taille="petit" onClick={() => setModuleModif(null)}>
                            Annuler
                        </Bouton>
                    </div>
                </form>
            );
        }
        return (
            <>
                <div className="mm-item-info">
                    <span className="mm-item-ordre">{module.ordre}</span>
                    <div>
                        <p className="mm-item-titre">{module.titre}</p>
                        <p className="mm-item-apercu">
                            {module.contenu?.slice(0, 60)}
                            {module.contenu?.length > 60 ? '...' : ''}
                        </p>
                    </div>
                </div>
                <div className="mm-item-actions">
                    <Bouton
                        variante="secondaire"
                        taille="petit"
                        onClick={() => setModuleModif({ ...module })}
                    >
                        Modifier
                    </Bouton>
                    <Bouton
                        variante="danger"
                        taille="petit"
                        onClick={() => handleSupprimer(module.id)}
                    >
                        Supprimer
                    </Bouton>
                </div>
            </>
        );
    };

    // Helper extrayant la branche conditionnelle (chargement / vide / liste).
    // Évite le ternaire imbriqué que SonarLint flagge (javascript:S3358).
    const renderModulesListe = () => {
        if (chargement) {
            return <p className="mm-chargement">Chargement...</p>;
        }
        if (modules.length === 0) {
            return <p className="mm-vide">Aucun module pour cette formation.</p>;
        }
        return (
            <div className="mm-liste">
                {modules.map((module) => (
                    <div key={module.id} className="mm-item">
                        {renderModuleCard(module)}
                    </div>
                ))}
            </div>
        );
    };

    return (
        <div className="mm-overlay" onClick={handleOverlayClick}>
            <div className="mm-boite">

                <button className="mm-fermer" onClick={onFermer}>✕</button>

                <h2 className="mm-titre">
                    Modules — {formation.titre}
                </h2>

                {messageOk && <p className="mm-succes">{messageOk}</p>}
                {erreur    && <p className="mm-erreur">{erreur}</p>}

                {/* Liste des modules existants */}
                {renderModulesListe()}

                {/* Formulaire ajout module */}
                {ajoutVisible ? (
                    <form onSubmit={handleAjouter} className="mm-form-ajout">
                        <h3 className="mm-form-titre">Nouveau module</h3>

                        <label className="mm-label">Titre</label>
                        <input
                            type="text"
                            value={titre}
                            onChange={(e) => setTitre(e.target.value)}
                            className="mm-input"
                            placeholder="Titre du module"
                            required
                        />

                        <label className="mm-label">Contenu</label>
                        <textarea
                            value={contenu}
                            onChange={(e) => setContenu(e.target.value)}
                            className="mm-textarea"
                            placeholder="Contenu du module"
                            rows={4}
                            required
                        />

                        <label className="mm-label">Ordre</label>
                        <input
                            type="number"
                            value={ordre}
                            onChange={(e) => setOrdre(parseInt(e.target.value))}
                            className="mm-input-ordre"
                            min={1}
                            required
                        />

                        <div className="mm-item-actions">
                            <Bouton
                                type="submit"
                                variante="principal"
                                taille="petit"
                                disabled={loadingAjout}
                            >
                                {loadingAjout ? 'Ajout...' : 'Ajouter'}
                            </Bouton>
                            <Bouton
                                variante="secondaire"
                                taille="petit"
                                onClick={() => setAjoutVisible(false)}
                            >
                                Annuler
                            </Bouton>
                        </div>
                    </form>
                ) : (
                    <div className="mm-btn-ajouter">
                        <Bouton
                            variante="fantome"
                            taille="petit"
                            onClick={() => setAjoutVisible(true)}
                        >
                            + Ajouter un module
                        </Bouton>
                    </div>
                )}

            </div>
        </div>
    );
}