import React, { useState } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import { ChevronDown } from 'lucide-react';
export function FAQSection() {
  const faqs = [
  {
    q: 'Do I need to install anything?',
    a: "No. It's entirely web-based — just open a browser and log in from any device."
  },
  {
    q: 'Can I manage multiple restaurant locations?',
    a: 'Yes. All plans support multiple restaurants under a single admin account.'
  },
  {
    q: 'How many staff accounts can I add?',
    a: 'There is no limit on staff accounts.'
  },
  {
    q: 'Is there a free trial?',
    a: 'Yes — 30 days, no credit card required. You get full access to the core features.'
  },
  {
    q: 'Can I cancel anytime?',
    a: 'Yes. Cancel at any time from your billing settings.'
  },
  {
    q: 'Do you offer yearly billing by invoice?',
    a: 'Yes. Contact us to arrange annual payment by bank transfer / invoice.'
  },
  {
    q: 'What payment methods do you accept?',
    a: 'Credit and debit cards via Stripe. Annual plans can also be paid by invoice.'
  },
  {
    q: 'Is my data secure?',
    a: 'All data is isolated per account. Passwords are hashed (bcrypt). Sessions expire after inactivity. HTTPS enforced.'
  },
  {
    q: 'What is the public booking link?',
    a: 'A unique URL guests can visit to book a table directly — without calling. Available on Advanced and Premium plans. (Coming soon)'
  },
  {
    q: 'Can I embed a booking form on my own website?',
    a: 'Yes, on the Premium plan. A small JavaScript snippet lets you embed the booking form directly into any webpage. (Coming soon)'
  }];

  const [openIndex, setOpenIndex] = useState<number | null>(0);
  return (
    <section id="faq" className="py-24 bg-cream">
      <div className="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="text-center mb-16">
          <h2 className="text-3xl md:text-4xl font-bold text-forest mb-4">
            Frequently asked questions
          </h2>
        </div>

        <div className="space-y-4">
          {faqs.map((faq, index) =>
          <div
            key={index}
            className="bg-white border border-sage-light rounded-2xl overflow-hidden">
            
              <button
              onClick={() => setOpenIndex(openIndex === index ? null : index)}
              className="w-full px-6 py-5 text-left flex justify-between items-center focus:outline-none">
              
                <span className="font-bold text-forest pr-8">{faq.q}</span>
                <ChevronDown
                className={`text-terracotta transition-transform duration-300 flex-shrink-0 ${openIndex === index ? 'rotate-180' : ''}`}
                size={20} />
              
              </button>

              <AnimatePresence>
                {openIndex === index &&
              <motion.div
                initial={{
                  height: 0,
                  opacity: 0
                }}
                animate={{
                  height: 'auto',
                  opacity: 1
                }}
                exit={{
                  height: 0,
                  opacity: 0
                }}
                transition={{
                  duration: 0.3,
                  ease: 'easeInOut'
                }}>
                
                    <div className="px-6 pb-5 text-forest/70 leading-relaxed border-t border-sage-light/30 pt-4">
                      {faq.a}
                    </div>
                  </motion.div>
              }
              </AnimatePresence>
            </div>
          )}
        </div>
      </div>
    </section>);

}