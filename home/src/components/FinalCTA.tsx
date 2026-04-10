import React from 'react';
import { motion } from 'framer-motion';

const APP_URL = import.meta.env.VITE_APP_URL ?? '';
export function FinalCTA() {
  return (
    <section className="py-24 bg-forest relative overflow-hidden">
      {/* Background decoration */}
      <div className="absolute inset-0 opacity-10">
        <div className="absolute -top-24 -right-24 w-96 h-96 bg-sage rounded-full blur-3xl"></div>
        <div className="absolute -bottom-24 -left-24 w-96 h-96 bg-terracotta rounded-full blur-3xl"></div>
      </div>

      <div className="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 text-center relative z-10">
        <motion.h2
          initial={{
            opacity: 0,
            y: 20
          }}
          whileInView={{
            opacity: 1,
            y: 0
          }}
          viewport={{
            once: true
          }}
          className="text-4xl md:text-5xl font-bold text-cream mb-6">
          
          Ready to modernise your reservations?
        </motion.h2>
        <motion.p
          initial={{
            opacity: 0,
            y: 20
          }}
          whileInView={{
            opacity: 1,
            y: 0
          }}
          viewport={{
            once: true
          }}
          transition={{
            delay: 0.1
          }}
          className="text-xl text-cream/80 mb-10">
          
          Start your 30-day free trial — no credit card required.
        </motion.p>
        <motion.div
          initial={{
            opacity: 0,
            y: 20
          }}
          whileInView={{
            opacity: 1,
            y: 0
          }}
          viewport={{
            once: true
          }}
          transition={{
            delay: 0.2
          }}>
          
          <a
            href={`${APP_URL}/register.php`}
            className="inline-block bg-terracotta hover:bg-terracotta-hover text-white px-10 py-4 rounded-full font-bold text-lg transition-colors shadow-xl shadow-terracotta/20">
            
            Start Free Trial
          </a>
        </motion.div>
      </div>
    </section>);

}