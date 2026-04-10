import React from 'react';
import { motion } from 'framer-motion';
export function SocialProof() {
  const restaurants = [
  'Bistro Milano',
  'Café Central',
  'The Green Table',
  'Sakura Kitchen',
  'La Piazza'];

  return (
    <section className="py-12 border-y border-sage-light bg-cream-dark/30">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
        <p className="text-sm font-medium text-forest/50 uppercase tracking-wider mb-8">
          Trusted by restaurants across Europe
        </p>
        <div className="flex flex-wrap justify-center items-center gap-8 md:gap-16 opacity-60 grayscale hover:grayscale-0 transition-all duration-500">
          {restaurants.map((name, index) =>
          <motion.div
            key={name}
            initial={{
              opacity: 0,
              y: 10
            }}
            whileInView={{
              opacity: 1,
              y: 0
            }}
            viewport={{
              once: true
            }}
            transition={{
              delay: index * 0.1,
              duration: 0.5
            }}
            className="text-xl md:text-2xl font-bold font-serif text-forest">
            
              {name}
            </motion.div>
          )}
        </div>
      </div>
    </section>);

}