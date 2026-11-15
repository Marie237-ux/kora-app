import type { Locale } from './copy';

/**
 * Sample reviews shown ONLY on the demo site (PUBLIC_KORA_DEMO, "Données de test" banner) when a product has no
 * real review yet, so the layout can be judged. They are labelled as examples on the page, never stored, and
 * disappear as soon as demo mode is off. Real reviews (verified purchases from the API) always win.
 */
export type SampleReview = {
  id: string;
  author: string;
  avatar: string | null;
  verified_purchase: boolean;
  rating: number;
  title: string | null;
  body: string;
  photos: string[];
  reply: string | null;
  created_at: string;
};

const days = (n: number) => new Date(Date.now() - n * 86400000).toISOString();

export function sampleReviews(locale: Locale, photo: string | null): SampleReview[] {
  const fr = locale === 'fr';
  const pics = photo ? [photo] : [];
  return [
    { id: 's1', author: 'Mireille K.', avatar: null, verified_purchase: true, rating: 5, created_at: days(6), photos: pics,
      title: fr ? 'Exactement comme sur la photo' : 'Exactly like the photo',
      body: fr ? 'Commandé le matin, reçu le soir même à Makepe. Le produit est conforme à la description et bien emballé. Je recommande la boutique.' : 'Ordered in the morning, received the same evening in Makepe. The product matches the description and was well packed. I recommend the shop.',
      reply: null },
    { id: 's2', author: 'Aïcha N.', avatar: null, verified_purchase: true, rating: 5, created_at: days(13), photos: [],
      title: fr ? 'Très bonne qualité' : 'Very good quality',
      body: fr ? 'Finitions soignées, rien à redire. Le livreur m’a appelée avant d’arriver, c’était simple.' : 'Neat finish, nothing to fault. The courier called me before arriving, it was easy.',
      reply: null },
    { id: 's3', author: 'Grâce T.', avatar: null, verified_purchase: true, rating: 4, created_at: days(21), photos: pics,
      title: fr ? 'Bien, mais livraison un peu longue' : 'Good, slightly slow delivery',
      body: fr ? 'Produit parfait. La livraison à Bonabéri a pris un jour de plus que prévu, mais j’ai été prévenue et j’ai pu suivre la course.' : 'Perfect product. Delivery to Bonabéri took one day longer than planned, but I was told and could follow the ride.',
      reply: fr ? 'Merci Grâce. Le pont était bloqué ce jour-là : nous avons ajouté un créneau du matin pour Bonabéri.' : 'Thank you Grace. The bridge was blocked that day: we added a morning slot for Bonabéri.' },
    { id: 's4', author: 'Sandrine E.', avatar: null, verified_purchase: true, rating: 5, created_at: days(34), photos: [],
      title: fr ? 'Je recommande' : 'I recommend it',
      body: fr ? 'Deuxième achat chez cette boutique via KORA. Prix identique à celui du magasin, et je n’ai pas eu à me déplacer à Akwa.' : 'Second purchase from this shop through KORA. Same price as in store, and I did not have to go to Akwa.',
      reply: null },
    { id: 's5', author: 'Larissa M.', avatar: null, verified_purchase: true, rating: 4, created_at: days(48), photos: [],
      title: fr ? 'Conforme' : 'As described',
      body: fr ? 'Rien à redire sur le produit. J’aurais aimé un emballage cadeau, mais l’équipe m’a répondu vite sur WhatsApp.' : 'Nothing to fault on the product. I would have liked gift wrapping, but the team answered quickly on WhatsApp.',
      reply: null },
    { id: 's6', author: 'Christelle B.', avatar: null, verified_purchase: true, rating: 3, created_at: days(70), photos: [],
      title: fr ? 'Un peu différent de la photo' : 'Slightly different from the photo',
      body: fr ? 'La couleur est un peu plus claire en vrai que sur mon écran. Le produit reste de bonne qualité.' : 'The colour is a little lighter in real life than on my screen. The product is still good quality.',
      reply: fr ? 'Merci pour votre retour, Christelle. Nous avons demandé à la boutique des photos en lumière du jour.' : 'Thank you for your feedback, Christelle. We asked the shop for daylight photos.' },
  ];
}
