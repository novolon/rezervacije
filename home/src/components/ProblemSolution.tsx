import React from 'react';
import { motion } from 'framer-motion';
import { XCircle, CheckCircle2 } from 'lucide-react';
export function ProblemSolution() {
  const problems = [
  'Error-prone phone logs and messy handwriting',
  'No real-time visibility for the whole team',
  'Impossible to scale across multiple locations',
  'Staff confusion during busy service hours'];

  const solutions = [
  'Clean, visual calendar everyone can read',
  'Real-time sync across all devices instantly',
  'Manage all your restaurants from one account',
  'Built-in staff roles and simple interfaces'];

  return (
    <section className="py-24 bg-white">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-16 items-center">
          {/* Problem Side */}
          <motion.div
            initial={{
              opacity: 0,
              x: -20
            }}
            whileInView={{
              opacity: 1,
              x: 0
            }}
            viewport={{
              once: true
            }}
            transition={{
              duration: 0.6
            }}
            className="bg-cream p-8 md:p-12 rounded-3xl border border-sage-light">
            
            <h2 className="text-3xl font-bold text-forest mb-8">
              Still managing reservations on paper?
            </h2>
            <ul className="space-y-6">
              {problems.map((text, i) =>
              <li key={i} className="flex items-start gap-4">
                  <XCircle
                  className="text-terracotta flex-shrink-0 mt-1"
                  size={24} />
                
                  <span className="text-lg text-forest/80">{text}</span>
                </li>
              )}
            </ul>
          </motion.div>

          {/* Solution Side */}
          <motion.div
            initial={{
              opacity: 0,
              x: 20
            }}
            whileInView={{
              opacity: 1,
              x: 0
            }}
            viewport={{
              once: true
            }}
            transition={{
              duration: 0.6,
              delay: 0.2
            }}
            className="bg-forest p-8 md:p-12 rounded-3xl shadow-xl">
            
            <h2 className="text-3xl font-bold text-cream mb-8">
              Meet Rezervacije
            </h2>
            <ul className="space-y-6">
              {solutions.map((text, i) =>
              <li key={i} className="flex items-start gap-4">
                  <CheckCircle2
                  className="text-sage flex-shrink-0 mt-1"
                  size={24} />
                
                  <span className="text-lg text-cream/90">{text}</span>
                </li>
              )}
            </ul>
          </motion.div>
        </div>
      </div>
    </section>);

}