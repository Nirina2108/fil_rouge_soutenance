import { useState } from 'react';
import paymentService from '../services/paymentService';
import { formaterPrix } from '../utils/formaterPrix';
import Bouton from './Bouton';
import './ModalPaiement.css';

/**
 * Modal de paiement (simulé) pour acheter une formation payante.
 *
 * Affiche un formulaire de carte bancaire FACTICE (numéro, expiration, CVV)
 * uniquement pour la démo visuelle — ces champs ne sont JAMAIS envoyés au
 * backend. Seul le mot de passe re-saisi est transmis pour ré-authentifier
 * l'utilisateur (anti vol de session).
 *
 * Sécurité côté backend (cf. PaymentService) :
 *   - Vérification du mot de passe en temps constant (Hash::check)
 *   - Recalcul du montant depuis la BDD (anti price-tampering)
 *   - Trace de toutes les tentatives (réussies ET échouées) en table payments
 *   - Transaction atomique paiement + inscription
 *
 * @param {object} props
 * @param {object} props.formation Formation à acheter (doit avoir id, titre, prix)
 * @param {function} props.onFermer Callback pour fermer la modal
 * @param {function} props.onSucces Callback après paiement réussi (rafraîchit la liste)
 */
export default function ModalPaiement({ formation, onFermer, onSucces }) {
    // États du formulaire carte (purement cosmétique, jamais envoyé au backend).
    const [numeroCarte, setNumeroCarte] = useState('');
    const [expiration, setExpiration] = useState('');
    const [cvv, setCvv] = useState('');
    // Champ critique : mot de passe envoyé au backend pour ré-authentification.
    const [motDePasse, setMotDePasse] = useState('');

    // Drapeaux UI.
    const [chargement, setChargement] = useState(false);
    const [erreur, setErreur] = useState('');

    // Ferme la modal au clic sur l'overlay (pas sur le contenu interne).
    const handleOverlayClick = (e) => {
        if (e.target === e.currentTarget) onFermer();
    };

    /**
     * Formate le numéro de carte au fil de la frappe (4-4-4-4).
     * Purement esthétique, sans aucune validation Luhn (mock).
     */
    const handleNumeroChange = (e) => {
        const valeur = e.target.value.replace(/\s/g, '').slice(0, 16);
        const formate = valeur.replace(/(.{4})/g, '$1 ').trim();
        setNumeroCarte(formate);
    };

    /**
     * Formate l'expiration MM/AA au fil de la frappe.
     */
    const handleExpirationChange = (e) => {
        const valeur = e.target.value.replace(/\D/g, '').slice(0, 4);
        if (valeur.length >= 3) {
            setExpiration(valeur.slice(0, 2) + '/' + valeur.slice(2));
        } else {
            setExpiration(valeur);
        }
    };

    // Soumission du paiement : on n'envoie QUE formationId + motDePasse au backend.
    const handleSubmit = async (e) => {
        e.preventDefault();
        setErreur('');
        setChargement(true);

        try {
            const resultat = await paymentService.confirmer({
                formationId: formation.id,
                motDePasse,
            });
            // Notifie le parent (qui referme la modal et rafraîchit l'UI).
            onSucces(resultat);
        } catch (e) {
            // Mapping des erreurs backend pour l'utilisateur.
            const code = e.response?.data?.erreur;
            const message = e.response?.data?.message;
            if (code === 'mot_de_passe_incorrect') {
                setErreur('Mot de passe incorrect.');
            } else if (code === 'deja_inscrit') {
                setErreur('Vous êtes déjà inscrit à cette formation.');
            } else if (code === 'formation_gratuite') {
                setErreur('Cette formation est gratuite, pas besoin de paiement.');
            } else if (code === 'role_invalide') {
                setErreur('Seul un apprenant peut acheter une formation.');
            } else {
                setErreur(message || 'Erreur lors du paiement, veuillez réessayer.');
            }
        } finally {
            setChargement(false);
        }
    };

    return (
        <div className="mp-overlay" onClick={handleOverlayClick}>
            <div className="mp-boite">
                <button className="mp-fermer" onClick={onFermer} aria-label="Fermer">✕</button>

                <div className="mp-entete">
                    <h2 className="mp-titre">Paiement sécurisé</h2>
                    <p className="mp-sous-titre">{formation.titre}</p>
                    <div className="mp-montant">
                        <span className="mp-montant-label">Montant</span>
                        <span className="mp-montant-valeur">{formaterPrix(formation.prix)}</span>
                    </div>
                </div>

                {/* Bandeau d'information sécurité — affirme la confidentialité du paiement. */}
                <div className="mp-securite">
                    🔒 Vos informations sont chiffrées. Nous ne stockons jamais votre numéro de carte.
                </div>

                {erreur && <p className="mp-erreur">{erreur}</p>}

                <form onSubmit={handleSubmit} className="mp-formulaire">

                    {/* Section "Carte bancaire" — UI uniquement, ces champs ne sont
                        jamais transmis au backend (mock pour la soutenance). */}
                    <fieldset className="mp-section">
                        <legend className="mp-legend">Informations de carte</legend>

                        <label className="mp-label">Numéro de carte</label>
                        <input
                            type="text"
                            value={numeroCarte}
                            onChange={handleNumeroChange}
                            className="mp-input"
                            placeholder="1234 5678 9012 3456"
                            autoComplete="off"
                            inputMode="numeric"
                            required
                        />

                        <div className="mp-ligne">
                            <div className="mp-ligne-champ">
                                <label className="mp-label">Expiration</label>
                                <input
                                    type="text"
                                    value={expiration}
                                    onChange={handleExpirationChange}
                                    className="mp-input"
                                    placeholder="MM/AA"
                                    inputMode="numeric"
                                    required
                                />
                            </div>
                            <div className="mp-ligne-champ">
                                <label className="mp-label">CVV</label>
                                <input
                                    type="password"
                                    value={cvv}
                                    onChange={(e) => setCvv(e.target.value.replace(/\D/g, '').slice(0, 4))}
                                    className="mp-input"
                                    placeholder="•••"
                                    inputMode="numeric"
                                    required
                                />
                            </div>
                        </div>
                    </fieldset>

                    {/* Section critique : ré-authentification mot de passe.
                        Ce champ EST envoyé au backend pour validation Hash::check. */}
                    <fieldset className="mp-section">
                        <legend className="mp-legend">Confirmation</legend>
                        <label className="mp-label">
                            Retapez votre mot de passe SkillHub
                        </label>
                        <input
                            type="password"
                            value={motDePasse}
                            onChange={(e) => setMotDePasse(e.target.value)}
                            className="mp-input"
                            placeholder="••••••••"
                            autoComplete="current-password"
                            required
                        />
                        <p className="mp-aide">
                            Vous devez confirmer votre identité avant chaque paiement (sécurité).
                        </p>
                    </fieldset>

                    <div className="mp-actions">
                        <Bouton
                            type="submit"
                            variante="principal"
                            taille="grand"
                            disabled={chargement}
                        >
                            {chargement
                                ? 'Traitement...'
                                : `Payer ${formaterPrix(formation.prix)}`}
                        </Bouton>
                        <Bouton variante="secondaire" taille="grand" onClick={onFermer}>
                            Annuler
                        </Bouton>
                    </div>
                </form>
            </div>
        </div>
    );
}
